<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Recette d'une box classique : « cette box contient N fois ce cookie ».
 * (Remplace l'ancien lien simple box_produit, qui n'avait pas de quantité.)
 */
#[ORM\Entity]
#[ORM\Table(name: 'box_composition')]
#[ORM\UniqueConstraint(name: 'uniq_box_composition_box_produit', columns: ['box_id', 'produit_id'])]
class BoxProduit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'compositions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Box $box = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Produit $produit = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $quantite = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBox(): ?Box
    {
        return $this->box;
    }

    public function setBox(?Box $box): static
    {
        $this->box = $box;

        return $this;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(1, $quantite);

        return $this;
    }

    public function __toString(): string
    {
        return $this->quantite . ' × ' . ($this->produit?->getName() ?? '?');
    }
}
