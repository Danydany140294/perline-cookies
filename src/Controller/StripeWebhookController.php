<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Service\PaiementCommandeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private PaiementCommandeService $paiementCommandeService,
        private LoggerInterface $logger,
        #[Autowire(param: 'retrait')] private array $retrait
    ) {}

    /**
     * E-mail de confirmation (client connecté). Un échec d'envoi n'annule pas la commande
     * mais il est désormais écrit dans les logs au lieu d'être avalé en silence.
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

    #[Route('/webhook/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
    public function handleWebhook(
        Request $request): JsonResponse
    {
        $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('stripe-signature');

        // ✅ Vérifier la signature Stripe
        try {
            \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Signature invalide'], 400);
        }

        // ✅ Session expirée — paiement abandonné
        if ($event->type === 'checkout.session.expired') {
            return new JsonResponse(['status' => 'session_expired']);
        }

        // ✅ On traite uniquement le paiement confirmé
        if ($event->type !== 'checkout.session.completed') {
            return new JsonResponse(['status' => 'ignored']);
        }

        $stripeSession = $event->data->object;

        // ✅ Parcours normal (client connecté) : la commande « En attente » existe déjà,
        //    son id nous revient dans client_reference_id. On la vérifie puis on la finalise.
        $commandeId = $stripeSession->client_reference_id ?? null;
        if ($commandeId !== null && ctype_digit((string) $commandeId)) {
            [$resultat, $commande] = $this->paiementCommandeService->finaliser(
                (int) $commandeId,
                (string) $stripeSession->id,
                (string) $stripeSession->payment_status,
                $stripeSession->currency ?? null,
                (int) $stripeSession->amount_total
            );

            if ($resultat === PaiementCommandeService::PAYEE && $commande?->getUser()) {
                $this->envoyerConfirmation($commande);
            }

            // Toujours 200 : un échec fonctionnel (ex. commande introuvable) est déjà journalisé,
            // et un 500 ferait seulement réessayer Stripe pour rien.
            return new JsonResponse(['status' => $resultat]);
        }

        // Plus d'achat invité : toute session Stripe créée par le site porte un client_reference_id.
        // Une session sans référence est anormale (ancienne session, ou paiement venu d'ailleurs) :
        // on ne crée AUCUNE commande à l'aveugle, on alerte pour vérification manuelle.
        $this->logger->critical('Webhook Stripe : paiement reçu sans référence de commande, à vérifier manuellement.', [
            'stripe_session_id' => $stripeSession->id,
            'montant_paye_centimes' => (int) $stripeSession->amount_total,
            'email' => $stripeSession->customer_email ?? null,
        ]);

        return new JsonResponse(['status' => 'sans_reference']);
    }
}