<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Frais de livraison, calculés UNIQUEMENT côté serveur, d'après la configuration (paramètre `livraison`
 * de config/services.yaml) : forfait, seuil de gratuité et modes proposés. Jamais de montant venant du navigateur.
 */
class FraisLivraisonService
{
    public const MODE_LIVRAISON = 'livraison';
    public const MODE_RETRAIT = 'retrait';

    /** Valeurs par défaut (celles de services.yaml) : utilisées si le service est créé sans configuration (tests). */
    public const CONFIG_PAR_DEFAUT = [
        'transporteur' => 'Colissimo',
        'modes' => ['livraison' => true, 'retrait' => true],
        'frais_forfait_centimes' => 450,
        'seuil_gratuite_centimes' => 5000,
    ];

    public function __construct(
        #[Autowire(expression: 'service("parametres_boutique").livraison()')] private array $livraison = self::CONFIG_PAR_DEFAUT
    ) {
    }

    /** Forfait de livraison à domicile, en centimes. */
    public function tarifCentimes(): int
    {
        return (int) $this->livraison['frais_forfait_centimes'];
    }

    /** Livraison offerte à partir de ce montant d'articles (seuil inclus), en centimes. */
    public function seuilGratuiteCentimes(): int
    {
        return (int) $this->livraison['seuil_gratuite_centimes'];
    }

    public function transporteur(): string
    {
        return (string) ($this->livraison['transporteur'] ?? 'Colissimo');
    }

    /** @return list<string> les modes proposés (self::MODE_*), livraison d'abord */
    public function modesDisponibles(): array
    {
        $modes = [];
        foreach ([self::MODE_LIVRAISON, self::MODE_RETRAIT] as $mode) {
            if (!empty($this->livraison['modes'][$mode])) {
                $modes[] = $mode;
            }
        }

        return $modes;
    }

    public function estDisponible(string $mode): bool
    {
        return in_array($mode, $this->modesDisponibles(), true);
    }

    /** Mode présenté par défaut (affichage du panier) : le premier proposé, ou null si aucun. */
    public function modeParDefaut(): ?string
    {
        return $this->modesDisponibles()[0] ?? null;
    }

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

        if ($sousTotalCentimes >= $this->seuilGratuiteCentimes()) {
            return 0;
        }

        return $this->tarifCentimes();
    }
}
