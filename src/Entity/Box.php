<?php

namespace App\Entity;

use App\Repository\BoxRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: BoxRepository::class)]
class Box
{
    /** Tailles autorisées pour la box personnalisable (une seule définition pour tout le projet). */
    public const TAILLES_PERSO = [6, 12, 24];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column]
    private ?float $prix = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $image = null;

    /** Plus utilisé : le stock d'une box se déduit des stocks de ses cookies (colonne conservée, à supprimer au nettoyage). */
    #[ORM\Column]
    private ?int $stock = 0;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(nullable: true)]
    private ?int $taille = null;

    #[ORM\Column(nullable: true)]
private ?float $prix6 = null;

#[ORM\Column(nullable: true)]
private ?float $prix12 = null;

#[ORM\Column(nullable: true)]
private ?float $prix24 = null;

    /** Taux de TVA en % applicable à ce prix TTC (5,5 % par défaut ; modifiable dans l'administration). */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, options: ['default' => '5.50'])]
    #[Assert\Range(min: 0, max: 30, notInRangeMessage: 'Le taux de TVA doit être compris entre {{ min }} et {{ max }} %.')]
    private string $tauxTva = '5.50';
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $createur = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateCreation = null;

    /**
     * Recette de la box (box classique) : cookies et quantités.
     *
     * @var Collection<int, BoxProduit>
     */
    #[ORM\OneToMany(targetEntity: BoxProduit::class, mappedBy: 'box', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $compositions;

    public function __construct()
    {
        $this->compositions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrix(): ?float
    {
        return $this->prix;
    }

    public function setPrix(float $prix): static
    {
        $this->prix = $prix;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(string $image): static
    {
        $this->image = $image;
        return $this;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = $stock;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTaille(): ?int
    {
        return $this->taille;
    }

    public function setTaille(?int $taille): static
    {
        $this->taille = $taille;
        return $this;
    }

    public function getPrix6(): ?float { return $this->prix6; }
public function setPrix6(?float $prix6): static { $this->prix6 = $prix6; return $this; }

public function getPrix12(): ?float { return $this->prix12; }
public function setPrix12(?float $prix12): static { $this->prix12 = $prix12; return $this; }

public function getPrix24(): ?float { return $this->prix24; }
public function setPrix24(?float $prix24): static { $this->prix24 = $prix24; return $this; }


    /**
     * Prix de la box personnalisable pour une taille donnée : SEULE source de vérité = les champs
     * « prix6 / prix12 / prix24 » saisis dans l'admin. Null si le prix n'est pas défini (ou nul) :
     * il n'y a volontairement AUCUN prix de repli (le champ « prix » ne sert qu'aux box classiques).
     */
    public function getPrixPourTaille(int $taille): ?float
    {
        $prix = match ($taille) {
            6 => $this->prix6,
            12 => $this->prix12,
            24 => $this->prix24,
            default => null,
        };

        return ($prix !== null && $prix > 0) ? $prix : null;
    }

    /** Une box personnalisable doit avoir un prix > 0 pour chacune de ses tailles (sinon elle serait vendue à 0 €). */
    #[Assert\Callback]
    public function validerPrixDesTailles(ExecutionContextInterface $context): void
    {
        if (!$this->estPersonnalisable()) {
            return;
        }

        foreach (self::TAILLES_PERSO as $taille) {
            if ($this->getPrixPourTaille($taille) === null) {
                $context->buildViolation('Le prix de la box de {{ taille }} cookies est obligatoire (supérieur à 0).')
                    ->setParameter('{{ taille }}', (string) $taille)
                    ->atPath('prix' . $taille)
                    ->addViolation();
            }
        }
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreateur(): ?User
    {
        return $this->createur;
    }

    public function setCreateur(?User $createur): static
    {
        $this->createur = $createur;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(?\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    /**
     * @return Collection<int, BoxProduit>
     */
    public function getCompositions(): Collection
    {
        return $this->compositions;
    }

    public function addComposition(BoxProduit $composition): static
    {
        if (!$this->compositions->contains($composition)) {
            $this->compositions->add($composition);
            $composition->setBox($this);
        }

        return $this;
    }

    public function removeComposition(BoxProduit $composition): static
    {
        if ($this->compositions->removeElement($composition) && $composition->getBox() === $this) {
            $composition->setBox(null);
        }

        return $this;
    }

    /** Raccourci : ajoute N fois un cookie à la recette (ou augmente sa quantité). */
    public function ajouterCookie(Produit $produit, int $quantite = 1): static
    {
        foreach ($this->compositions as $composition) {
            if ($composition->getProduit() === $produit) {
                $composition->setQuantite($composition->getQuantite() + $quantite);

                return $this;
            }
        }

        return $this->addComposition((new BoxProduit())->setProduit($produit)->setQuantite($quantite));
    }

    /**
     * Nombre de boxes que l'on peut encore fabriquer avec les stocks actuels des cookies
     * (le plus limitant l'emporte). 0 si la recette est vide ou si un cookie est indisponible.
     */
    public function getStockDisponible(): int
    {
        $possibles = null;

        foreach ($this->compositions as $composition) {
            $produit = $composition->getProduit();
            $n = ($produit && $produit->isDisponible())
                ? intdiv(max(0, (int) $produit->getStock()), max(1, $composition->getQuantite()))
                : 0;
            $possibles = $possibles === null ? $n : min($possibles, $n);
        }

        return $possibles ?? 0;
    }
    /**
     * Vérifie si la box est personnalisable
     */
    public function estPersonnalisable(): bool
    {
        return $this->type === 'personnalisable';
    }

    /**
     * Vérifie si la box a été créée par un utilisateur (box perso)
     */
    public function estBoxPersoUser(): bool
    {
        return $this->createur !== null;
    }

    /**
     * Pour l'affichage dans EasyAdmin
     */
    public function __toString(): string
    {
        return $this->nom ?? 'Box #' . $this->id;
    }

    public function getTauxTva(): float
    {
        return (float) $this->tauxTva;
    }

    public function setTauxTva(float|string|null $tauxTva): static
    {
        $this->tauxTva = number_format((float) ($tauxTva ?? 5.5), 2, '.', '');

        return $this;
    }

    /** Disponibilité lisible (calculée) : désactivée par l'administrateur, en rupture ou en stock. */
    public function getStatutDisponibilite(): string
    {
        if (!$this->isActive) {
            return 'Désactivée';
        }
        if ($this->getStockDisponible() <= 0) {
            return 'Rupture';
        }

        return 'En stock (' . $this->getStockDisponible() . ')';
    }
}
