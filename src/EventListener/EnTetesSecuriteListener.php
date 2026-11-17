<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité ajoutés à toutes les réponses (sans écraser un en-tête déjà défini par un contrôleur).
 *
 * Pas de Content-Security-Policy pour l'instant : le site charge encore Tailwind depuis un CDN et utilise des scripts en ligne
 * (voir la liste de questions de la sécurité) ; elle viendra avec le chapitre front (build de Tailwind, scripts avec nonce).
 * HSTS : envoyé seulement en production ET en HTTPS (jamais en local) ; le serveur web peut aussi le définir.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
class EnTetesSecuriteListener
{
    public const ENTETES = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    public function __construct(#[Autowire('%kernel.environment%')] private string $environnement = 'prod')
    {
    }

    public function __invoke(ResponseEvent $evenement): void
    {
        if (!$evenement->isMainRequest()) {
            return;
        }
        $reponse = $evenement->getResponse();
        foreach (self::ENTETES as $nom => $valeur) {
            if (!$reponse->headers->has($nom)) {
                $reponse->headers->set($nom, $valeur);
            }
        }
        if ($this->environnement === 'prod' && $evenement->getRequest()->isSecure() && !$reponse->headers->has('Strict-Transport-Security')) {
            $reponse->headers->set('Strict-Transport-Security', 'max-age=15552000'); // 180 jours, sans includeSubDomains ni preload
        }
    }
}
