<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\CommandeHistorique;
use App\Enum\CommandeStatut;

/**
 * Remise en stock APRÈS annulation ou remboursement : jamais automatique (produits alimentaires : un cookie
 * rendu ou annulé n'est pas forcément revendable). C'est l'administrateur qui la décide, en cochant la case
 * « Stock remis en vente » de la commande. Possible une seule fois, et seulement si le système avait retiré le stock.
 */
class RestitutionStockService
{
    public function __construct(
        private StockCookiesService $stockCookies,
        private \Doctrine\ORM\EntityManagerInterface $em
    ) {
    }

    /** @throws \DomainException avec un message affichable à l'administrateur */
    public function restituer(Commande $commande, ?string $auteur = null): void
    {
        if (!in_array($commande->getStatut(), [CommandeStatut::ANNULEE, CommandeStatut::REMBOURSEE], true)) {
            throw new \DomainException('La remise en stock n\'est possible que pour une commande annulée ou remboursée.');
        }
        if (!$commande->isStockRetire()) {
            throw new \DomainException('Le stock de cette commande n\'a jamais été retiré par le système (commande antérieure au suivi ou jamais payée) : rien à remettre.');
        }
        if ($commande->isStockRestitue()) {
            throw new \DomainException('Le stock de cette commande a déjà été remis en vente.');
        }

        $resume = [];
        foreach ($this->stockCookies->besoinsDepuisCommande($commande) as ['produit' => $produit, 'quantite' => $quantite]) {
            $produit->incrementerStock($quantite);
            $resume[] = sprintf('%d × %s', $quantite, $produit->getName());
        }

        $commande->setStockRestitue(true);
        $statut = $commande->getStatut()->value;
        $this->em->persist(new CommandeHistorique(
            $commande,
            $statut,
            $statut,
            CommandeHistorique::SOURCE_ADMIN,
            $auteur,
            'Stock remis en vente : ' . ($resume === [] ? 'aucun article' : implode(', ', $resume)) . '.'
        ));
    }
}
