<?php

namespace App\Entity;

use App\Repository\ProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProduitRepository::class)]
class Produit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?float $prix = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    /** Taux de TVA en % applicable à ce prix TTC (5,5 % par défaut ; modifiable dans l'administration). */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, options: ['default' => '5.50'])]
    #[Assert\Range(min: 0, max: 30, notInRangeMessage: 'Le taux de TVA doit être compris entre {{ min }} et {{ max }} %.')]
    private string $tauxTva = '5.50';
    #[ORM\Column]
    private ?int $stock = null;

    /** Seuil d'alerte de stock propre à ce cookie (facultatif : sinon le seuil global de config/boutique.yaml). */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le seuil d\'alerte doit être positif ou nul.')]
    private ?int $seuilAlerte = null;

    /** Alerte de stock déjà envoyée pour la baisse en cours (remis à faux quand le stock repasse au-dessus du seuil). */
    #[ORM\Column(options: ['default' => false])]
    private bool $alerteStockEnvoyee = false;

    #[ORM\Column(length: 255)]
    private ?string $image = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $disponible = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateCreation = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateModification = null;

    // ========================================
    // RELATIONS
    // ========================================


    /**
     * @var Collection<int, Avis>
     */
    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'produit', cascade: ['remove'])]
    private Collection $avis;

    /**
     * @var Collection<int, User>
     * Utilisateurs qui ont mis ce produit en favori
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'favoris')]
    private Collection $utilisateursFavoris;

    public function __construct()
    {
        $this->avis = new ArrayCollection();
        $this->utilisateursFavoris = new ArrayCollection();
        $this->dateCreation = new \DateTime();
    }

    // ========================================
    // GETTERS & SETTERS BASIQUES
    // ========================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->dateModification = new \DateTime();
        return $this;
    }

    public function getPrix(): ?float
    {
        return $this->prix;
    }

    public function setPrix(float $prix): static
    {
        $this->prix = $prix;
        $this->dateModification = new \DateTime();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        $this->dateModification = new \DateTime();
        return $this;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = $stock;
        $this->dateModification = new \DateTime();
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(string $image): static
    {
        $this->image = $image;
        $this->dateModification = new \DateTime();
        return $this;
    }

    /**
     * Disponible = ACTIF (réglage de l'administrateur) ET en stock (stock > 0). Calculé : on n'écrit plus jamais
     * « indisponible » quand le stock tombe à 0, donc un réapprovisionnement remet le produit en vente tout seul sans
     * jamais annuler une désactivation volontaire.
     */
    public function isDisponible(): bool
    {
        return $this->isActive && (int) $this->stock > 0;
    }

    /** Actif mais stock épuisé (rupture) : calculé, sans rien modifier en base. */
    public function isEnRupture(): bool
    {
        return $this->isActive && (int) $this->stock <= 0;
    }

    /**
     * @deprecated Colonne « disponible » conservée en base mais plus utilisée : voir isDisponible() (actif ET stock > 0).
     */
    public function setDisponible(bool $disponible): static
    {
        $this->disponible = $disponible;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        $this->dateModification = new \DateTime();
        return $this;
    }

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeInterface $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getDateModification(): ?\DateTimeInterface
    {
        return $this->dateModification;
    }

    public function setDateModification(?\DateTimeInterface $dateModification): static
    {
        $this->dateModification = $dateModification;
        return $this;
    }

    // ========================================
    // GESTION DES AVIS
    // ========================================

    /**
     * @return Collection<int, Avis>
     */
    public function getAvis(): Collection
    {
        return $this->avis;
    }

    public function addAvi(Avis $avi): static
    {
        if (!$this->avis->contains($avi)) {
            $this->avis->add($avi);
            $avi->setProduit($this);
        }
        return $this;
    }

    public function removeAvi(Avis $avi): static
    {
        if ($this->avis->removeElement($avi)) {
            if ($avi->getProduit() === $this) {
                $avi->setProduit(null);
            }
        }
        return $this;
    }

    /**
     * Compte le nombre d'avis approuvés
     */
    public function getNombreAvis(): int
    {
        return $this->avis->filter(function($avis) {
            return $avis->isApprouve() && $avis->isVisible();
        })->count();
    }

    /**
     * Retourne les avis approuvés uniquement
     * 
     * @return Collection<int, Avis>
     */
    public function getAvisApprouves(): Collection
    {
        return $this->avis->filter(function($avis) {
            return $avis->isApprouve() && $avis->isVisible();
        });
    }

    /**
     * Calcule la note moyenne du produit
     */
    public function getNoteMoyenne(): float
    {
        $avisApprouves = $this->getAvisApprouves();
        
        if ($avisApprouves->isEmpty()) {
            return 0;
        }
        
        $total = 0;
        foreach ($avisApprouves as $avis) {
            $total += $avis->getNote();
        }
        
        return round($total / $avisApprouves->count(), 1);
    }

    // ========================================
    // GESTION DES FAVORIS
    // ========================================

    /**
     * @return Collection<int, User>
     */
    public function getUtilisateursFavoris(): Collection
    {
        return $this->utilisateursFavoris;
    }

    public function addUtilisateurFavori(User $utilisateur): static
    {
        if (!$this->utilisateursFavoris->contains($utilisateur)) {
            $this->utilisateursFavoris->add($utilisateur);
            $utilisateur->addFavori($this);
        }
        return $this;
    }

    public function removeUtilisateurFavori(User $utilisateur): static
    {
        if ($this->utilisateursFavoris->removeElement($utilisateur)) {
            $utilisateur->removeFavori($this);
        }
        return $this;
    }

    /**
     * Compte le nombre d'utilisateurs ayant mis ce produit en favori
     */
    public function getNombreFavoris(): int
    {
        return $this->utilisateursFavoris->count();
    }

    /**
     * Vérifie si un utilisateur a mis ce produit en favori
     */
    public function isFavoriPar(User $utilisateur): bool
    {
        return $this->utilisateursFavoris->contains($utilisateur);
    }

    // ========================================
    // MÉTHODES MÉTIER
    // ========================================

    /**
     * Retourne le prix formaté avec devise
     */
    public function getPrixFormatte(): string
    {
        return number_format($this->prix, 2, ',', ' ') . ' €';
    }

    /**
     * Vérifie si le produit est en stock
     */
    public function isEnStock(): bool
    {
        return $this->stock > 0;
    }

    /**
     * Vérifie si le produit est en rupture de stock
     */
    public function isRuptureStock(): bool
    {
        return $this->stock === 0;
    }

    /**
     * Vérifie si le stock est faible (moins de 10)
     */
    public function isStockFaible(): bool
    {
        return $this->stock > 0 && $this->stock < 10;
    }

    /**
     * Vérifie si le produit peut être acheté
     */
    public function isAchetable(): bool
    {
        return $this->isDisponible();
    }

    /**
     * Retourne le message de disponibilité
     */
    public function getMessageDisponibilite(): string
    {
        if (!$this->isActive) {
            return 'Produit indisponible';
        }
        
        if ($this->isRuptureStock()) {
            return 'Rupture de stock';
        }
        
        if ($this->isStockFaible()) {
            return sprintf('Plus que %d en stock !', $this->stock);
        }
        
        return 'En stock';
    }

    /**
     * Retourne la classe CSS du badge de disponibilité
     */
    public function getBadgeDisponibiliteClass(): string
    {
        if (!$this->isActive) {
            return 'badge-danger';
        }
        
        if ($this->isRuptureStock()) {
            return 'badge-danger';
        }
        
        if ($this->isStockFaible()) {
            return 'badge-warning';
        }
        
        return 'badge-success';
    }

    /**
     * Retourne une description courte (100 caractères max)
     */
    public function getDescriptionCourte(int $longueur = 100): string
    {
        if (!$this->description) {
            return '';
        }
        
        if (mb_strlen($this->description) <= $longueur) {
            return $this->description;
        }
        
        return mb_substr($this->description, 0, $longueur) . '...';
    }

    /**
     * Décrémente le stock (lors d'un achat)
     */
    public function decrémenterStock(int $quantite = 1): static
    {
        // Jamais de stock négatif masqué : l'appelant doit avoir vérifié le stock avant.
        if ($quantite > (int) $this->stock) {
            throw new \DomainException(sprintf('Stock insuffisant pour %s : %d demandé(s), %d disponible(s).', $this->name, $quantite, (int) $this->stock));
        }

        $this->stock -= $quantite;
        $this->dateModification = new \DateTime();

        // Plus jamais de disponible = false à 0 : la rupture est CALCULÉE (voir isDisponible()).

        return $this;
    }

    /**
     * Incrémente le stock (lors d'un réapprovisionnement)
     */
    public function incrementerStock(int $quantite = 1): static
    {
        $this->stock += $quantite;
        $this->dateModification = new \DateTime();

        // La disponibilité est calculée (actif ET stock > 0) : un réapprovisionnement remet en vente automatiquement,
        // et un produit désactivé à la main le reste.

        return $this;
    }

    /**
     * Vérifie si le produit est nouveau (moins de 30 jours)
     */
    public function isNouveau(): bool
    {
        if (!$this->dateCreation) {
            return false;
        }
        
        $maintenant = new \DateTime();
        $diff = $maintenant->diff($this->dateCreation);
        
        return $diff->days < 30;
    }

    /**
     * Vérifie si le produit est populaire (plus de 10 favoris)
     */
    public function isPopulaire(): bool
    {
        return $this->getNombreFavoris() >= 10;
    }

    /**
     * Retourne les badges du produit (nouveau, populaire, etc.)
     * 
     * @return array<string>
     */
    public function getBadges(): array
    {
        $badges = [];
        
        if ($this->isNouveau()) {
            $badges[] = 'Nouveau';
        }
        
        if ($this->isPopulaire()) {
            $badges[] = 'Populaire';
        }
        
        if ($this->getNoteMoyenne() >= 4.5) {
            $badges[] = 'Excellent';
        }
        
        if ($this->isStockFaible()) {
            $badges[] = 'Stock limité';
        }
        
        return $badges;
    }

    /**
     * Pour l'affichage dans EasyAdmin et les formulaires
     */
    public function __toString(): string
    {
        return $this->name ?? 'Produit #' . $this->id;
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

    public function getSeuilAlerte(): ?int { return $this->seuilAlerte; }

    public function setSeuilAlerte(?int $seuilAlerte): static
    {
        $this->seuilAlerte = $seuilAlerte;

        return $this;
    }

    /** Seuil effectif : celui du produit s'il est renseigné, sinon le seuil global. */
    public function getSeuilEffectif(int $seuilGlobal): int
    {
        return $this->seuilAlerte ?? $seuilGlobal;
    }

    public function isAlerteStockEnvoyee(): bool { return $this->alerteStockEnvoyee; }

    public function setAlerteStockEnvoyee(bool $envoyee): static
    {
        $this->alerteStockEnvoyee = $envoyee;

        return $this;
    }
}
