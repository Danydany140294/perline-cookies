<?php

namespace App\Service;

use App\Entity\Commande;
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

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
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

            if ($ecarts !== []) {
                $commande->setStatut(CommandeStatut::A_VERIFIER);
                $this->em->flush();

                $this->logger->critical('Webhook Stripe : paiement reçu avec un écart, commande à vérifier.', [
                    'commande_id' => $commande->getId(),
                    'numero' => $commande->getNumeroCommande(),
                    'ecarts' => $ecarts,
                    'montant_attendu_centimes' => $montantAttenduCentimes,
                    'montant_paye_centimes' => $montantPayeCentimes,
                    'devise' => $devise,
                    'stripe_session_id' => $stripeSessionId,
                ]);

                return [self::A_VERIFIER, $commande];
            }

            // Paiement conforme : seulement maintenant on touche au stock et au panier.
            // (Dans la même transaction que le changement de statut : tout ou rien.)
            $commande->setStatut(CommandeStatut::PAYEE);

            // Stock : comportement conservé (produits simples). Boxes et cookies des boxes
            // personnalisées : point D du plan.
            foreach ($commande->getLignesCommande() as $ligne) {
                $ligne->getProduit()?->decrémenterStock($ligne->getQuantite());
            }

            // Vider le panier du client
            $user = $commande->getUser();
            if ($user !== null) {
                $panier = $this->em->getRepository(Panier::class)->findOneBy(['user' => $user]);
                if ($panier !== null) {
                    foreach ($panier->getLignesPanier() as $lignePanier) {
                        $this->em->remove($lignePanier);
                    }
                    $this->em->remove($panier);
                }
            }

            $this->em->flush();

            return [self::PAYEE, $commande];
        });
    }
}
