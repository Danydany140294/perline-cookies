<?php

namespace App\Service;

/**
 * Seul endroit de l'application où les frais de livraison sont calculés.
 * Tous les montants sont en centimes (entiers) pour éviter les erreurs d'arrondi.
 */
class FraisLivraisonService
{
    public const MODE_LIVRAISON = 'livraison';
    public const MODE_RETRAIT = 'retrait';

    /** Tarif de livraison : 4,50 € */
    public const TARIF_LIVRAISON_CENTIMES = 450;

    /** Livraison offerte à partir de 50,00 € (seuil inclus) */
    public const SEUIL_GRATUITE_CENTIMES = 5000;

    /**
     * @param int    $sousTotalCentimes Sous-total des articles, en centimes
     * @param string $mode              self::MODE_LIVRAISON ou self::MODE_RETRAIT
     *
     * @return int Frais en centimes (0 si gratuit)
     *
     * @throws \InvalidArgumentException si le sous-total est négatif ou le mode inconnu
     */
    public function calculer(int $sousTotalCentimes, string $mode = self::MODE_LIVRAISON): int
    {
        if ($sousTotalCentimes < 0) {
            throw new \InvalidArgumentException('Le sous-total ne peut pas être négatif.');
        }

        if (!in_array($mode, [self::MODE_LIVRAISON, self::MODE_RETRAIT], true)) {
            throw new \InvalidArgumentException(sprintf('Mode de livraison inconnu : "%s".', $mode));
        }

        // Retrait sur place, ou panier vide : rien à livrer, rien à facturer
        if ($mode === self::MODE_RETRAIT || $sousTotalCentimes === 0) {
            return 0;
        }

        if ($sousTotalCentimes >= self::SEUIL_GRATUITE_CENTIMES) {
            return 0;
        }

        return self::TARIF_LIVRAISON_CENTIMES;
    }
}
