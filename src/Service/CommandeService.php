<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\User;
use App\Enum\CommandeStatut;

/**
 * Création de la commande « En attente » au clic sur « Payer ». Les lignes, le stock et les statuts suivants sont gérés par
 * PaiementCommandeService (webhook), RemboursementCommandeService et ChangementStatutCommande (administration).
 */
class CommandeService
{
    public function __construct(private NumeroCommandeGenerator $numeroCommandeGenerator)
    {
    }

    /**
     * Crée une nouvelle commande
     */
    public function creerCommande(User $user): Commande
    {
        $commande = new Commande();
        $commande->setUser($user);
        $commande->setNumeroCommande($this->numeroCommandeGenerator->generer());
        $commande->setStatut(CommandeStatut::EN_ATTENTE);
        $commande->setDateCommande(new \DateTime());
        $commande->setTotalTTC(0);

        return $commande;
    }
}
