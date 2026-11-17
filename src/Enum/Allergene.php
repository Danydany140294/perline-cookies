<?php

namespace App\Enum;

/** Les 14 allergènes à déclaration obligatoire (règlement européen INCO n° 1169/2011, annexe II). */
enum Allergene: string
{
    case GLUTEN = 'gluten';
    case CRUSTACES = 'crustaces';
    case OEUFS = 'oeufs';
    case POISSONS = 'poissons';
    case ARACHIDES = 'arachides';
    case SOJA = 'soja';
    case LAIT = 'lait';
    case FRUITS_A_COQUE = 'fruits_a_coque';
    case CELERI = 'celeri';
    case MOUTARDE = 'moutarde';
    case SESAME = 'sesame';
    case SULFITES = 'sulfites';
    case LUPIN = 'lupin';
    case MOLLUSQUES = 'mollusques';

    public function label(): string
    {
        return match ($this) {
            self::GLUTEN => 'Céréales contenant du gluten (blé, seigle, orge, avoine...)',
            self::CRUSTACES => 'Crustacés',
            self::OEUFS => 'Œufs',
            self::POISSONS => 'Poissons',
            self::ARACHIDES => 'Arachides',
            self::SOJA => 'Soja',
            self::LAIT => 'Lait (y compris lactose)',
            self::FRUITS_A_COQUE => 'Fruits à coque (amandes, noisettes, noix, pistaches, macadamia...)',
            self::CELERI => 'Céleri',
            self::MOUTARDE => 'Moutarde',
            self::SESAME => 'Graines de sésame',
            self::SULFITES => 'Anhydride sulfureux et sulfites',
            self::LUPIN => 'Lupin',
            self::MOLLUSQUES => 'Mollusques',
        };
    }

    /** Libellé court pour les listes et les cartes. */
    public function court(): string
    {
        return match ($this) {
            self::GLUTEN => 'Gluten',
            self::LAIT => 'Lait',
            self::FRUITS_A_COQUE => 'Fruits à coque',
            self::SULFITES => 'Sulfites',
            self::SESAME => 'Sésame',
            default => explode(' (', $this->label())[0],
        };
    }

    /** @return array<string, string> libellé complet => valeur (pour les formulaires) */
    public static function choix(): array
    {
        $choix = [];
        foreach (self::cases() as $a) {
            $choix[$a->label()] = $a->value;
        }

        return $choix;
    }

    /**
     * Fusion des allergènes de plusieurs cookies, dans l'ordre réglementaire. Retourne null si l'un des cookies n'est pas renseigné
     * (information incomplète : on ne dit jamais « sans allergène » à tort).
     *
     * @param iterable<\App\Entity\Produit|null> $produits
     *
     * @return list<self>|null
     */
    public static function union(iterable $produits): ?array
    {
        $valeurs = [];
        foreach ($produits as $produit) {
            $liste = $produit?->getAllergenes();
            if ($liste === null) {
                return null;
            }
            foreach ($liste as $v) {
                $valeurs[$v] = true;
            }
        }

        return array_values(array_filter(self::cases(), static fn (self $a): bool => isset($valeurs[$a->value])));
    }
}
