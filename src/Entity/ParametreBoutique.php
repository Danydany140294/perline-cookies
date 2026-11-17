<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage de la boutique modifié depuis l'administration (« Paramètres de la boutique »). Une ligne = une valeur qui diffère
 * de la valeur par défaut de config/boutique.yaml / config/services.yaml. Jamais de secret ici (clés Stripe, DSN : variables d'environnement).
 * Lue et écrite par App\Service\ParametresBoutique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'parametre_boutique')]
class ParametreBoutique
{
    /** Ex. « boutique.nom_marque », « livraison.frais_forfait_centimes », « retrait.lieu » */
    #[ORM\Id]
    #[ORM\Column(name: 'cle', length: 100)]
    private string $cle;

    /** Valeur encodée en JSON */
    #[ORM\Column(type: 'text')]
    private string $valeur;

    #[ORM\Column(name: 'modifie_le', type: 'datetime_immutable')]
    private \DateTimeImmutable $modifieLe;

    public function __construct(string $cle, string $valeur)
    {
        $this->cle = $cle;
        $this->valeur = $valeur;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }
}
