<?php

namespace App\Service;

use App\Entity\Commande;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Lien de suivi du colis (adresse configurable : `livraison.suivi_url`, {numero} est remplacé par le numéro de suivi). */
class SuiviColis
{
    public function __construct(#[Autowire(param: 'livraison')] private array $livraison = [])
    {
    }

    /** Lien de suivi, ou null s'il n'y a pas de numéro ou si le transporteur n'est pas celui du lien configuré. */
    public function lien(Commande $commande): ?string
    {
        $numero = $commande->getNumeroSuivi();
        $modele = $this->livraison['suivi_url'] ?? null;
        if ($numero === null || $modele === null) {
            return null;
        }

        $transporteur = $commande->getTransporteur();
        if ($transporteur !== null && strcasecmp($transporteur, (string) ($this->livraison['transporteur'] ?? 'Colissimo')) !== 0) {
            return null; // autre transporteur : on n'invente pas de lien
        }

        return str_replace('{numero}', rawurlencode($numero), $modele);
    }
}
