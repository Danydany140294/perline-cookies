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
}
