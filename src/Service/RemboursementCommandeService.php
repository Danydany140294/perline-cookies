<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\CommandeHistorique;
use App\Enum\CommandeStatut;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Remboursement fait dans le tableau de bord Stripe, signalé par l'événement `charge.refunded` :
 * - remboursement TOTAL  -> la commande passe « Remboursée » ;
 * - remboursement PARTIEL -> le montant remboursé est enregistré et une note est ajoutée à l'historique,
 *   le statut ne change pas (un remboursement partiel ne termine pas la commande) ;
 * - idempotent : un événement rejoué ne change rien ;
 * - le STOCK n'est jamais remis automatiquement (produits alimentaires) : c'est à l'administrateur de le cocher.
 */
class RemboursementCommandeService
{
    public const REMBOURSEE = 'remboursee';
    public const REMBOURSEMENT_PARTIEL = 'remboursement_partiel';
    public const DEJA_TRAITEE = 'deja_traitee';
    public const INTROUVABLE = 'introuvable';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ChangementStatutCommande $statuts,
        private StripeEvenements $stripe
    ) {
    }

    /**
     * @param int  $montantRembourseCentimes total CUMULÉ remboursé (amount_refunded de la charge Stripe)
     * @param bool $remboursementTotal       la charge est entièrement remboursée
     *
     * @return array{0: string, 1: ?Commande}
     */
    public function enregistrer(
        string $paymentIntentId,
        int $montantRembourseCentimes,
        bool $remboursementTotal,
        string $source = CommandeHistorique::SOURCE_WEBHOOK
    ): array {
        $commande = $this->retrouver($paymentIntentId);
        if ($commande === null) {
            $this->logger->critical('Webhook Stripe : remboursement reçu pour un paiement inconnu.', [
                'payment_intent' => $paymentIntentId,
                'montant_rembourse_centimes' => $montantRembourseCentimes,
            ]);

            return [self::INTROUVABLE, null];
        }

        return $this->em->wrapInTransaction(function () use ($commande, $montantRembourseCentimes, $remboursementTotal, $source): array {
            $this->em->find(Commande::class, $commande->getId(), LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($commande);

            // Idempotence : un événement qui n'apporte rien de nouveau (rejeu, ou événement ancien arrivé après un plus
            // récent) ne change rien. Seul un remboursement TOTAL pas encore acté (statut pas « Remboursée ») continue.
            $dejaCentimes = (int) round($commande->getMontantRembourse() * 100);
            if ($montantRembourseCentimes <= $dejaCentimes
                && ($commande->getStatut() === CommandeStatut::REMBOURSEE || !$remboursementTotal)) {
                return [self::DEJA_TRAITEE, $commande];
            }
            $commande->setMontantRembourse($montantRembourseCentimes / 100);
            $montant = number_format($montantRembourseCentimes / 100, 2, ',', ' ') . ' €';

            if (!$remboursementTotal) {
                $this->noter($commande, $source, sprintf('Remboursement partiel dans Stripe : %s remboursés (total commande %s €).', $montant, number_format((float) $commande->getTotalTTC(), 2, ',', ' ')));
                $this->em->flush();

                return [self::REMBOURSEMENT_PARTIEL, $commande];
            }

            $statut = $commande->getStatut();
            if ($statut !== null && $this->statuts->peutAller($commande, $statut, CommandeStatut::REMBOURSEE)) {
                $this->statuts->appliquer($commande, CommandeStatut::REMBOURSEE, $source, null, sprintf('Remboursement total dans Stripe (%s). Stock non remis automatiquement.', $montant));
            } else {
                // Remboursement d'une commande qui n'était pas payée chez nous : à regarder à la main
                $this->logger->critical('Webhook Stripe : remboursement total reçu pour une commande qui ne peut pas passer « Remboursée ».', ['commande_id' => $commande->getId(), 'statut' => $statut?->value]);
                if ($statut !== null && $this->statuts->peutAller($commande, $statut, CommandeStatut::A_VERIFIER)) {
                    $this->statuts->appliquer($commande, CommandeStatut::A_VERIFIER, $source, null, sprintf('Remboursement total Stripe (%s) reçu alors que la commande était « %s ».', $montant, $statut->getLibelle()));
                }
            }

            $this->em->flush();

            return [self::REMBOURSEE, $commande];
        });
    }

    /** Commande du paiement : par l'identifiant du paiement, sinon (anciennes commandes) via la session Stripe. */
    private function retrouver(string $paymentIntentId): ?Commande
    {
        $repository = $this->em->getRepository(Commande::class);
        $commande = $repository->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
        if ($commande !== null) {
            return $commande;
        }

        try {
            $sessionId = $this->stripe->sessionDuPaiement($paymentIntentId);
        } catch (\Throwable $e) {
            $this->logger->error('Remboursement : impossible de retrouver la session du paiement chez Stripe.', ['erreur' => $e->getMessage()]);

            return null;
        }

        $commande = $sessionId ? $repository->findOneBy(['stripeSessionId' => $sessionId]) : null;
        $commande?->setStripePaymentIntentId($paymentIntentId);

        return $commande;
    }

    /** Ajoute une note à l'historique (sans changement de statut). */
    private function noter(Commande $commande, string $source, string $note): void
    {
        $statut = $commande->getStatut()->value;
        $this->em->persist(new CommandeHistorique($commande, $statut, $statut, $source, null, $note));
    }
}
