<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\Facture;
use App\Entity\CommandeHistorique;
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
        private RemboursementCommandeService $remboursementService,
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        #[Autowire(param: 'retrait')] private array $retrait,
        private IdentiteBoutique $boutique,
        private Facturation $facturation,
        private AlertesAdmin $alertes
    ) {
    }

    /** @return string statut lisible (constantes de PaiementCommandeService ou de cette classe) */
    public function traiter(\Stripe\Event $event, string $source = CommandeHistorique::SOURCE_WEBHOOK): string
    {
        $session = $event->data->object ?? null;
        $reference = $session->client_reference_id ?? null;
        $aUneReference = $reference !== null && ctype_digit((string) $reference);

        // Session expirée : la commande « En attente » passe « Annulée » (jamais supprimée)
        if ($event->type === 'checkout.session.expired') {
            if (!$aUneReference) {
                return self::SESSION_EXPIREE_SANS_REFERENCE;
            }

            [$resultat] = $this->paiementCommandeService->annulerSessionExpiree((int) $reference, (string) $session->id, $source);

            return $resultat;
        }

        // Remboursement (fait dans le tableau de bord Stripe) : total -> « Remboursée », partiel -> note + montant
        if ($event->type === 'charge.refunded') {
            $paiement = (string) ($session->payment_intent ?? '');
            if ($paiement === '') {
                $this->logger->critical('Webhook Stripe : remboursement sans identifiant de paiement.', ['charge' => $session->id ?? null]);

                return self::SANS_REFERENCE;
            }

            $rembourse = (int) ($session->amount_refunded ?? 0);
            $total = ($session->refunded ?? false) === true || ($rembourse > 0 && $rembourse >= (int) ($session->amount ?? PHP_INT_MAX));

            [$resultat, $commandeRemboursee] = $this->remboursementService->enregistrer($paiement, $rembourse, $total, $source);

            // Avoir (référence la facture) pour la part remboursée ; un échec ne doit jamais faire échouer le webhook
            if ($commandeRemboursee !== null && in_array($resultat, [RemboursementCommandeService::REMBOURSEE, RemboursementCommandeService::REMBOURSEMENT_PARTIEL], true)) {
                try {
                    $avoir = $this->facturation->emettreAvoir($commandeRemboursee);
                    if ($avoir !== null) {
                        $this->envoyerDocument($commandeRemboursee, $avoir, 'Avoir ' . $avoir->getNumero() . ' pour votre commande ' . $commandeRemboursee->getNumeroCommande(), 'Un remboursement a été effectué sur votre commande. Vous trouverez l\'avoir en pièce jointe.');
                    }
                } catch (\Throwable $e) {
                    $this->logger->critical('Émission de l\'avoir impossible, à traiter à la main.', ['commande_id' => $commandeRemboursee->getId(), 'erreur' => $e->getMessage()]);
                }
            }

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
                (int) $session->amount_total,
                $source,
                isset($session->payment_intent) ? (string) $session->payment_intent : null
            );

            if ($resultat === PaiementCommandeService::PAYEE && $commande !== null) {
                // Facture émise au paiement (une seule fois) ; un échec est journalisé (commande "app:factures:emettre" pour rattraper)
                $facture = null;
                try {
                    $facture = $this->facturation->emettreFacture($commande);
                } catch (\Throwable $e) {
                    $this->logger->critical('Émission de la facture impossible, à rattraper avec app:factures:emettre.', ['commande_id' => $commande->getId(), 'erreur' => $e->getMessage()]);
                }
                if ($commande->getUser()) {
                    $this->envoyerConfirmation($commande, $facture);
                }
                $this->alertes->commandePayee($commande); // e-mail à l'administration (jamais bloquant)
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
    private function envoyerConfirmation(Commande $commande, ?Facture $facture = null): void
    {
        try {
            $html = $this->twig->render('emails/confirmation_commande.html.twig', [
                'commande' => $commande,
                'retrait' => $this->retrait,
            ]);

            $email = (new Email())
                ->from($this->boutique->expediteur())
                ->to($commande->getUser()->getEmail())
                ->subject('Confirmation de votre commande ' . $commande->getNumeroCommande())
                ->html($html);
            if ($facture !== null && ($chemin = $this->facturation->cheminPdf($facture)) !== null) {
                $email->attachFromPath($chemin, $facture->getNumero() . '.pdf', 'application/pdf');
            }
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec de l\'envoi de l\'e-mail de confirmation de commande.', [
                'numero' => $commande->getNumeroCommande(),
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /** E-mail simple avec un document PDF (avoir) en pièce jointe. */
    private function envoyerDocument(Commande $commande, Facture $document, string $sujet, string $texte): void
    {
        if (!$commande->getUser()?->getEmail() || ($chemin = $this->facturation->cheminPdf($document)) === null) {
            return;
        }
        try {
            $this->mailer->send(
                (new Email())
                    ->from($this->boutique->expediteur())
                    ->to($commande->getUser()->getEmail())
                    ->subject($sujet)
                    ->text($texte)
                    ->attachFromPath($chemin, $document->getNumero() . '.pdf', 'application/pdf')
            );
        } catch (\Throwable $e) {
            $this->logger->error('Échec de l\'envoi du document.', ['numero' => $document->getNumero(), 'erreur' => $e->getMessage()]);
        }
    }
}
