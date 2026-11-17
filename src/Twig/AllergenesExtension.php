<?php

namespace App\Twig;

use App\Entity\Box;
use App\Entity\LignePanier;
use App\Entity\Produit;
use App\Enum\Allergene;
use App\Repository\ProduitRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Allergènes dans les gabarits : cookie, box (calcul automatique d'après ses cookies) et ligne de panier.
 * Résultat : ['connus' => bool, 'liste' => list<string> libellés courts, 'selection' => bool]. « connus » = faux si un cookie concerné n'est
 * pas renseigné (on n'affirme jamais « sans allergène » sans l'information). « selection » = box personnalisable : liste de TOUS les cookies.
 */
class AllergenesExtension extends AbstractExtension
{
    public function __construct(private ProduitRepository $produits)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('allergenes_produit', fn (Produit $p): array => $this->formater(Allergene::union([$p]))),
            new TwigFunction('allergenes_box', fn (Box $b): array => $this->box($b)),
            new TwigFunction('allergenes_ligne', fn (LignePanier $l): array => $this->ligne($l)),
        ];
    }

    /** @return array{connus: bool, liste: list<string>, selection: bool} */
    private function box(Box $box): array
    {
        if ($box->getCompositions()->isEmpty()) { // box personnalisable : le client choisit, on affiche tous les cookies possibles
            return ['selection' => true] + $this->formater(Allergene::union($this->produits->findAffichables()));
        }

        return $this->formater($box->getAllergenes());
    }

    /** @return array{connus: bool, liste: list<string>, selection: bool} */
    private function ligne(LignePanier $ligne): array
    {
        if ($ligne->getProduit() !== null) {
            return $this->formater(Allergene::union([$ligne->getProduit()]));
        }
        if ($ligne->getBox() !== null) {
            if ($ligne->getCompositionsPanier()->isEmpty()) {
                return $this->box($ligne->getBox());
            }

            return $this->formater(Allergene::union($ligne->getCompositionsPanier()->map(static fn ($c) => $c->getProduit())->toArray()));
        }

        return $this->formater(null);
    }

    /** @param list<Allergene>|null $allergenes @return array{connus: bool, liste: list<string>, selection: bool} */
    private function formater(?array $allergenes): array
    {
        return [
            'connus' => $allergenes !== null,
            'liste' => array_map(static fn (Allergene $a): string => $a->court(), $allergenes ?? []),
            'selection' => false,
        ];
    }
}
