<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Compteur de numérotation séquentielle, par type (facture / avoir) et par année. Verrouillé à chaque émission. */
#[ORM\Entity]
#[ORM\Table(name: 'facture_compteur')]
class FactureCompteur
{
    #[ORM\Id]
    #[ORM\Column(length: 10)]
    private string $type;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $annee;

    #[ORM\Column(options: ['default' => 0])]
    private int $derniereSequence = 0;

    public function getType(): string { return $this->type; }
    public function getAnnee(): int { return $this->annee; }
    public function getDerniereSequence(): int { return $this->derniereSequence; }
}
