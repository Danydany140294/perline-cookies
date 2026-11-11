<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\LignePanier;
use App\Entity\Produit;

/**
 * Le stock ne porte que sur les cookies : une box retire de chaque stock les cookies de sa recette.
 * Ce service calcule les besoins (cookies seuls + cookies des boxes classiques + cookies des box
 * personnalisées, cumulés par cookie) et les compare aux stocks.
 *
 * Besoins = tableau [id du cookie => ['produit' => Produit, 'quantite' => int]].
 */
class StockCookiesService
{
    /**
     * Avant paiement : à partir des lignes du panier (recette ACTUELLE des boxes classiques).
     *
     * @param iterable<LignePanier> $lignes
     */
    public function besoinsDepuisPanier(iterable $lignes): array
    {
        $besoins = [];

        foreach ($lignes as $ligne) {
            $quantite = (int) $ligne->getQuantite();

            if ($ligne->getProduit() !== null) {
                $this->ajouter($besoins, $ligne->getProduit(), $quantite);
            } elseif ($ligne->isBoxPersonnalisable()) {
                foreach ($ligne->getCompositionsPanier() as $compo) {
                    $this->ajouter($besoins, $compo->getProduit(), $quantite * (int) $compo->getQuantite());
                }
            } elseif ($ligne->getBox() !== null) {
                foreach ($ligne->getBox()->getCompositions() as $composition) {
                    $this->ajouter($besoins, $composition->getProduit(), $quantite * $composition->getQuantite());
                }
            }
        }

        return $besoins;
    }

    /**
     * Après paiement : à partir de la commande, avec la composition FIGÉE sur chaque ligne
     * (jamais la recette actuelle : modifier une box ne change pas les anciennes commandes).
     */
    public function besoinsDepuisCommande(Commande $commande): array
    {
        $besoins = [];

        foreach ($commande->getLignesCommande() as $ligne) {
            $quantite = (int) $ligne->getQuantite();

            if ($ligne->getProduit() !== null) {
                $this->ajouter($besoins, $ligne->getProduit(), $quantite);
            }

            foreach ($ligne->getCompositionsBox() as $compo) {
                $this->ajouter($besoins, $compo->getProduit(), $quantite * (int) $compo->getQuantite());
            }
        }

        return $besoins;
    }

    /**
     * @return list<string> un message par cookie en défaut (liste vide = stock suffisant)
     */
    public function verifier(array $besoins): array
    {
        $messages = [];

        foreach ($besoins as ['produit' => $produit, 'quantite' => $quantite]) {
            $stock = (int) $produit->getStock();

            if (!$produit->isDisponible()) {
                $messages[] = sprintf('%s n\'est plus disponible.', $produit->getName());
            } elseif ($stock < $quantite) {
                $messages[] = sprintf(
                    'Stock insuffisant pour %s : %d demandé(s), %d disponible(s).',
                    $produit->getName(),
                    $quantite,
                    $stock
                );
            }
        }

        return $messages;
    }

    /**
     * Boxes du panier qui ne sont plus vendables : désactivées, ou classiques sans recette
     * (on ne vendrait pas une box vide). Message par box concernée.
     *
     * @param iterable<LignePanier> $lignes
     * @return list<string>
     */
    public function verifierBoxes(iterable $lignes): array
    {
        $messages = [];

        foreach ($lignes as $ligne) {
            $box = $ligne->getBox();
            if ($box === null) {
                continue;
            }

            if (!$box->isActive()) {
                $messages[] = sprintf('La box « %s » n\'est plus disponible.', $box->getNom());
            } elseif (!$ligne->isBoxPersonnalisable() && !$box->estPersonnalisable() && $box->getCompositions()->isEmpty()) {
                $messages[] = sprintf('La box « %s » n\'est pas disponible pour le moment.', $box->getNom());
            }
        }

        return array_values(array_unique($messages));
    }
    /**
     * Garde-fou : aucune ligne du panier ne peut être payée à 0 € (prix non défini : box personnalisable
     * dont la taille n'a plus de prix dans l'admin, article sans prix...).
     *
     * @param iterable<LignePanier> $lignes
     * @return list<string>
     */
    public function verifierPrix(iterable $lignes): array
    {
        $messages = [];

        foreach ($lignes as $ligne) {
            if ($ligne->getPrixUnitaire() <= 0) {
                $messages[] = sprintf('Le prix de « %s » n\'est pas disponible pour le moment.', $ligne->getNomArticle());
            }
        }

        return array_values(array_unique($messages));
    }
    private function ajouter(array &$besoins, ?Produit $produit, int $quantite): void
    {
        if ($produit === null || $quantite <= 0) {
            return;
        }

        $id = $produit->getId() ?? spl_object_id($produit);
        $besoins[$id] ??= ['produit' => $produit, 'quantite' => 0];
        $besoins[$id]['quantite'] += $quantite;
    }
}
