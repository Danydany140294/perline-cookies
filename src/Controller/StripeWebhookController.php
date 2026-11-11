<?php

namespace App\Controller;

use App\Service\TraitementEvenementStripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(private TraitementEvenementStripe $traitement)
    {
    }

    #[Route('/webhook/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('stripe-signature', '');

        // ✅ Vérifier la signature Stripe
        try {
            \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Signature invalide'], 400);
        }

        // Toujours 200 une fois l'événement authentifié : un échec fonctionnel (commande introuvable,
        // écart...) est déjà journalisé, et un 500 ferait seulement réessayer Stripe pour rien.
        return new JsonResponse(['status' => $this->traitement->traiter($event)]);
    }
}
