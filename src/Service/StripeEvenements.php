<?php

namespace App\Service;

/**
 * Relit un événement directement chez Stripe (API authentifiée par la clé secrète) : l'événement est
 * donc authentique sans avoir besoin de la signature du webhook. Isolé ici pour pouvoir être simulé en test.
 */
class StripeEvenements
{
    public function recuperer(string $idEvenement): \Stripe\Event
    {
        $cle = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if ($cle === '') {
            throw new \RuntimeException('STRIPE_SECRET_KEY n\'est pas définie.');
        }

        return (new \Stripe\StripeClient($cle))->events->retrieve($idEvenement);
    }

    /** Identifiant de la session Checkout qui a produit ce paiement (pour retrouver les commandes antérieures au suivi du paiement). */
    public function sessionDuPaiement(string $paymentIntentId): ?string
    {
        $cle = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if ($cle === '') {
            throw new \RuntimeException('STRIPE_SECRET_KEY n\'est pas définie.');
        }

        $sessions = (new \Stripe\StripeClient($cle))->checkout->sessions->all(['payment_intent' => $paymentIntentId, 'limit' => 1]);

        return $sessions->data[0]->id ?? null;
    }
}
