<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Zone desservie : France métropolitaine, hors Corse pour le moment (paramètre `livraison.zone`).
 * Codes postaux 01 à 95, sauf les départements exclus (20 = Corse). DOM-TOM (97, 98) exclus. Pas d'étranger.
 * Renvoie un message CLAIR quand l'adresse n'est pas desservie.
 */
class ZoneLivraison
{
    public const CONFIG_PAR_DEFAUT = ['pays' => 'France', 'departements_exclus' => ['20', '97', '98']];

    private string $pays;
    /** @var list<string> */
    private array $exclus;

    public function __construct(#[Autowire(expression: 'service("parametres_boutique").livraison()')] array $livraison = ['zone' => self::CONFIG_PAR_DEFAUT])
    {
        $zone = $livraison['zone'] ?? self::CONFIG_PAR_DEFAUT;
        $this->pays = (string) ($zone['pays'] ?? 'France');
        $this->exclus = array_map('strval', $zone['departements_exclus'] ?? []);
    }

    public function pays(): string
    {
        return $this->pays;
    }

    /**
     * Pourquoi cette adresse n'est PAS desservie (message affichable), ou null si elle l'est.
     */
    public function raisonNonDesservie(?string $codePostal, ?string $pays): ?string
    {
        if (mb_strtolower(trim((string) $pays)) !== mb_strtolower($this->pays)) {
            return sprintf('nous livrons uniquement en %s métropolitaine pour le moment.', $this->pays);
        }

        return $this->raisonCodePostal($codePostal);
    }

    /** Raison propre au code postal (pays supposé correct), ou null s'il est desservi. */
    public function raisonCodePostal(?string $codePostal): ?string
    {
        $cp = trim((string) $codePostal);

        if (!preg_match('/^\d{5}$/', $cp)) {
            return 'le code postal doit comporter 5 chiffres.';
        }

        $departement = substr($cp, 0, 2);

        if ($departement === '00' || (int) $departement > 98) {
            return 'ce code postal n\'existe pas.';
        }

        if ($departement === '20' && in_array('20', $this->exclus, true)) {
            return 'nous ne livrons pas encore la Corse.';
        }

        if (in_array($departement, ['97', '98'], true) || in_array($departement, $this->exclus, true)) {
            return 'nous ne livrons pas encore l\'outre-mer ni Monaco (codes postaux 97 et 98).';
        }

        if ((int) $departement > 95) {
            return 'ce code postal n\'existe pas.';
        }

        return null;
    }

    public function estDesservie(?string $codePostal, ?string $pays): bool
    {
        return $this->raisonNonDesservie($codePostal, $pays) === null;
    }

    /** Message complet à afficher quand l'adresse est refusée (null si desservie). */
    public function messageNonDesservie(?string $codePostal, ?string $pays): ?string
    {
        $raison = $this->raisonNonDesservie($codePostal, $pays);

        return $raison === null ? null : 'Cette adresse n\'est pas desservie : ' . $raison;
    }
}
