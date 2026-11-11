<?php

namespace App\Service;

use App\Entity\Commande;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Traite un événement Stripe DÉJÀ authentifié (signature vérifiée par le webhook, ou événement relu
 * directement chez Stripe par la commande de rattrapage). Idempotent : rejouer un événement déjà
 * traité ne change rien (voir PaiementCommandeService) et n'envoie pas un second e-mail.
 */
class TraitementEvenementStripe
{
    public const IGNORE = 'ignored';
    public const SESSION_EXPIREE_SANS_REFERENCE = 'session_expired';
    public const SANS_REFERENCE = 'sans_reference';

    public function __construct(
        private PaiementCommandeService $paiementCommandeService,
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        #[Autowire(param: 'retrait')] private array $retrait
    ) {
    }

    /** @return string statut lisible (constantes de PaiementCommandeService ou de cette classe) */
    public function traiter(\Stripe\Event $event): string
    {
        $session = $event->data->object ?? null;
        $reference = $session->client_reference_id ?? null;
        $aUneReference = $reference !== null && ctype_digit((string) $reference);

        // Session expirée : la commande « En attente » passe « Annulée » (jamais supprimée)
        if ($event->type === 'checkout.session.expired') {
            if (!$aUneReference) {
                return self::SESSION_EXPIREE_SANS_REFERENCE;
            }

            [$resultat] = $this->paiementCommandeService->annulerSessionExpiree((int) $reference, (string) $session->id);

            return $resultat;
        }

        if ($event->type !== 'checkout.session.completed') {
            return self::IGNORE;
        }

        // Paiement confirmé : la commande « En attente » existe déjà (client_reference_id)
        if ($aUneReference) {
            [$resultat, $commande] = $this->paiementCommandeService->finaliser(
                (int) $reference,
                (string) $session->id,
                (string) $session->payment_status,
                $session->currency ?? null,
                (int) $session->amount_total
            );

            if ($resultat === PaiementCommandeService::PAYEE && $commande?->getUser()) {
                $this->envoyerConfirmation($commande);
            }

            return $resultat;
        }

        // Plus d'achat invité : toute session créée par le site porte une référence. Sans elle, on ne
        // crée AUCUNE commande à l'aveugle : alerte pour vérification manuelle.
        $this->logger->critical('Webhook Stripe : paiement reçu sans référence de commande, à vérifier manuellement.', [
            'stripe_session_id' => $session->id ?? null,
            'montant_paye_centimes' => (int) ($session->amount_total ?? 0),
            'email' => $session->customer_email ?? null,
        ]);

        return self::SANS_REFERENCE;
    }

    /**
     * E-mail de confirmation (client connecté). Un échec d'envoi n'annule pas la commande
     * mais il est écrit dans les logs au lieu d'être avalé en silence.
     */
    private function envoyerConfirmation(Commande $commande): void
    {
        try {
            $html = $this->twig->render('emails/confirmation_commande.html.twig', [
                'commande' => $commande,
                'retrait' => $this->retrait,
            ]);

            $this->mailer->send(
                (new Email())
                    ->from('noreply@creaself.fr')
                    ->to($commande->getUser()->getEmail())
                    ->subject('Confirmation de votre commande ' . $commande->getNumeroCommande())
                    ->html($html)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Échec de l\'envoi de l\'e-mail de confirmation de commande.', [
                'numero' => $commande->getNumeroCommande(),
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
