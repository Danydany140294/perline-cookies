<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\LignePanier;
use App\Entity\Panier;
use App\Enum\CommandeStatut;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Finalise une commande « En attente » quand Stripe confirme le paiement.
 *
 * Règle : on compare ce que Stripe a encaissé à ce qui a été figé dans la commande
 * au moment de la création de la session de paiement (et jamais au panier du moment).
 */
class PaiementCommandeService
{
    public const PAYEE = 'payee';
    public const A_VERIFIER = 'a_verifier';
    public const DEJA_TRAITEE = 'deja_traitee';
    public const NON_PAYEE = 'non_payee';
    public const INTROUVABLE = 'introuvable';
    public const ANNULEE = 'annulee';
    public const IGNOREE = 'ignoree';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private StockCookiesService $stockCookies
    ) {
    }

    /**
     * @return array{0: string, 1: ?Commande} [résultat (constantes ci-dessus), commande concernée]
     */
    public function finaliser(
        int $commandeId,
        string $stripeSessionId,
        string $paymentStatus,
        ?string $devise,
        int $montantPayeCentimes
    ): array {
        // Une seule transaction + verrou sur la ligne de la commande : si Stripe livre deux fois
        // le même événement en même temps, le second attend, puis constate que la commande
        // n'est plus « En attente » (idempotence).
        return $this->em->wrapInTransaction(function () use ($commandeId, $stripeSessionId, $paymentStatus, $devise, $montantPayeCentimes): array {
            $commande = $this->em->find(Commande::class, $commandeId, LockMode::PESSIMISTIC_WRITE);

            if ($commande === null) {
                $this->logger->critical('Webhook Stripe : commande introuvable pour un paiement reçu.', [
                    'commande_id' => $commandeId,
                    'stripe_session_id' => $stripeSessionId,
                    'montant_paye_centimes' => $montantPayeCentimes,
                ]);

                return [self::INTROUVABLE, null];
            }

            // Argent reçu sur une commande déjà « Annulée » (session expirée puis paiement tardif, ou
            // annulation manuelle) : on ne l'ignore SURTOUT pas, un humain doit regarder.
            if ($commande->getStatut() === CommandeStatut::ANNULEE && $paymentStatus === 'paid') {
                $commande->setStatut(CommandeStatut::A_VERIFIER);
                $this->em->flush();

                $this->logger->critical('Webhook Stripe : paiement reçu sur une commande annulée, commande à vérifier.', [
                    'commande_id' => $commande->getId(),
                    'numero' => $commande->getNumeroCommande(),
                    'montant_paye_centimes' => $montantPayeCentimes,
                    'stripe_session_id' => $stripeSessionId,
                ]);

                return [self::A_VERIFIER, $commande];
            }

            if ($commande->getStatut() !== CommandeStatut::EN_ATTENTE) {
                return [self::DEJA_TRAITEE, $commande];
            }

            if ($paymentStatus !== 'paid') {
                return [self::NON_PAYEE, $commande];
            }

            $montantAttenduCentimes = (int) round(($commande->getTotalTTC() ?? 0) * 100);

            $ecarts = [];
            if ($commande->getStripeSessionId() !== $stripeSessionId) {
                $ecarts[] = 'session_stripe_differente';
            }
            if (strtolower((string) $devise) !== 'eur') {
                $ecarts[] = 'devise_inattendue';
            }
            if ($montantPayeCentimes !== $montantAttenduCentimes) {
                $ecarts[] = 'montant_different';
            }

            // Stock : cookies seuls + cookies des boxes (composition FIGÉE sur les lignes), cumulés.
            // Les produits sont relus et verrouillés (dans l'ordre des id) : deux paiements simultanés
            // ne peuvent pas retirer deux fois le même dernier cookie.
            $besoins = $this->stockCookies->besoinsDepuisCommande($commande);
            ksort($besoins);
            if ($ecarts === []) {
                foreach ($besoins as ['produit' => $produit]) {
                    $this->em->refresh($produit, LockMode::PESSIMISTIC_WRITE);
                }
                $problemesStock = $this->stockCookies->verifier($besoins);
                if ($problemesStock !== []) {
                    $ecarts[] = 'stock_insuffisant';
                }
            }

            // Retrait du stock, tout ou rien : si un cookie manque (ou si Produit refuse de passer
            // en négatif), rien n'est retiré, la commande payée passe « À vérifier », réponse 200.
            if ($ecarts === []) {
                try {
                    foreach ($besoins as ['produit' => $produit, 'quantite' => $quantite]) {
                        $produit->decrémenterStock($quantite);
                    }
                } catch (\DomainException $e) {
                    foreach ($besoins as ['produit' => $produit]) {
                        $this->em->refresh($produit); // annule les retraits déjà faits en mémoire
                    }
                    $ecarts[] = 'stock_insuffisant';
                    $problemesStock = [$e->getMessage()];
                }
            }

            if ($ecarts !== []) {
                $commande->setStatut(CommandeStatut::A_VERIFIER);
                $this->em->flush();

                $this->logger->critical('Webhook Stripe : paiement reçu avec un écart, commande à vérifier.', [
                    'commande_id' => $commande->getId(),
                    'numero' => $commande->getNumeroCommande(),
                    'ecarts' => $ecarts,
                    'stock' => $problemesStock ?? [],
                    'montant_attendu_centimes' => $montantAttenduCentimes,
                    'montant_paye_centimes' => $montantPayeCentimes,
                    'devise' => $devise,
                    'stripe_session_id' => $stripeSessionId,
                ]);

                return [self::A_VERIFIER, $commande];
            }

            // Paiement conforme et stock retiré : on valide et on vide le panier.
            // (Même transaction que le changement de statut : tout ou rien.)
            $commande->setStatut(CommandeStatut::PAYEE);

            // Panier : seuls les articles PAYÉS en sortent (ce qui a été ajouté depuis, par exemple dans un
            // autre onglet, reste dans le panier du client).
            $this->retirerArticlesPayesDuPanier($commande);

            $this->em->flush();

            return [self::PAYEE, $commande];
        });
    }

    /**
     * Retire du panier du client ce que la commande contient, ligne à ligne (quantité par quantité).
     * Le panier lui-même n'est supprimé que s'il est vide ensuite.
     */
    private function retirerArticlesPayesDuPanier(Commande $commande): void
    {
        $user = $commande->getUser();
        if ($user === null) {
            return;
        }

        $panier = $this->em->getRepository(Panier::class)->findOneBy(['user' => $user]);
        if ($panier === null) {
            return;
        }

        foreach ($commande->getLignesCommande() as $ligneCommande) {
            $aRetirer = (int) $ligneCommande->getQuantite();

            foreach ($panier->getLignesPanier()->toArray() as $lignePanier) {
                if ($aRetirer <= 0) {
                    break;
                }
                if (!$this->memeArticle($lignePanier, $ligneCommande)) {
                    continue;
                }

                $retire = min($aRetirer, (int) $lignePanier->getQuantite());
                $aRetirer -= $retire;
                $reste = (int) $lignePanier->getQuantite() - $retire;

                if ($reste > 0) {
                    $lignePanier->setQuantite($reste);
                } else {
                    $panier->removeLignePanier($lignePanier);
                    $this->em->remove($lignePanier);
                }
            }
        }

        if ($panier->getLignesPanier()->isEmpty()) {
            $this->em->remove($panier);
        }
    }

    /** Une ligne de panier correspond-elle à une ligne de la commande ? (même cookie, même box, même composition) */
    private function memeArticle(LignePanier $lignePanier, LigneCommande $ligneCommande): bool
    {
        if ($ligneCommande->isBoxPersonnalisable()) {
            return $lignePanier->isBoxPersonnalisable()
                && $lignePanier->getBox() === $ligneCommande->getBox()
                && $this->signature($lignePanier->getCompositionsPanier()) === $this->signature($ligneCommande->getCompositionsBox());
        }

        if ($ligneCommande->getBox() !== null) {
            return $lignePanier->getBox() === $ligneCommande->getBox()
                && $lignePanier->getProduit() === null
                && !$lignePanier->isBoxPersonnalisable();
        }

        return $ligneCommande->getProduit() !== null && $lignePanier->getProduit() === $ligneCommande->getProduit();
    }

    /** Empreinte d'une composition (cookie:quantité, triée) pour comparer deux box personnalisées. */
    private function signature(iterable $compositions): string
    {
        $parties = [];
        foreach ($compositions as $composition) {
            $produit = $composition->getProduit();
            $parties[] = ($produit?->getId() ?? spl_object_id($produit)) . ':' . $composition->getQuantite();
        }
        sort($parties);

        return implode('|', $parties);
    }
    /**
     * Session Stripe expirée (paiement jamais fait) : la commande « En attente » passe « Annulée ».
     * Elle n'est PAS supprimée (traçabilité). Une commande déjà payée, à vérifier ou annulée n'est
     * jamais touchée, et la session doit correspondre à celle de la commande.
     *
     * @return array{0: string, 1: ?Commande}
     */
    public function annulerSessionExpiree(int $commandeId, string $stripeSessionId): array
    {
        return $this->em->wrapInTransaction(function () use ($commandeId, $stripeSessionId): array {
            $commande = $this->em->find(Commande::class, $commandeId, LockMode::PESSIMISTIC_WRITE);

            if ($commande === null) {
                $this->logger->warning('Webhook Stripe : session expirée pour une commande introuvable.', [
                    'commande_id' => $commandeId,
                    'stripe_session_id' => $stripeSessionId,
                ]);

                return [self::INTROUVABLE, null];
            }

            if ($commande->getStripeSessionId() !== $stripeSessionId) {
                $this->logger->warning('Webhook Stripe : session expirée qui ne correspond pas à la commande, ignorée.', [
                    'commande_id' => $commande->getId(),
                    'stripe_session_id' => $stripeSessionId,
                ]);

                return [self::IGNOREE, $commande];
            }

            if ($commande->getStatut() !== CommandeStatut::EN_ATTENTE) {
                return [self::DEJA_TRAITEE, $commande];
            }

            $commande->setStatut(CommandeStatut::ANNULEE);
            $this->em->flush();

            return [self::ANNULEE, $commande];
        });
    }
}