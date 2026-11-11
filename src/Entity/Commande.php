<?php

namespace App\Entity;

use App\Enum\CommandeStatut;
use App\Enum\ModeLivraison;
use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: CommandeRepository::class)]
#[ORM\Index(name: 'idx_commande_payment_intent', columns: ['stripe_payment_intent_id'])]
#[UniqueEntity(fields: ['numeroCommande'], message: 'Ce numéro de commande existe déjà.')]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Format PER-AAAAMMJJ-XXXXXX (voir NumeroCommandeGenerator) ; anciennes commandes : CMD-… */
    #[ORM\Column(length: 50, unique: true)]
    private ?string $numeroCommande = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateCommande = null;

    #[ORM\Column(length: 50, enumType: CommandeStatut::class)]
    private ?CommandeStatut $statut = null;

    #[ORM\Column]
    private ?float $totalTTC = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateLivraison = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateExpedition = null;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'commande', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lignesCommande;

    #[ORM\ManyToOne(targetEntity: Adresse::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Adresse $adresseLivraison = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $stripeSessionId = null;

    /** Livraison à domicile (adresse obligatoire) ou retrait sur place (pas d'adresse, frais à 0 €). */
    #[ORM\Column(length: 20, enumType: ModeLivraison::class, options: ['default' => 'livraison'])]
    private ModeLivraison $modeLivraison = ModeLivraison::LIVRAISON;

    /** Identifiant du paiement chez Stripe (pi_…) : sert à retrouver la commande quand Stripe signale un remboursement. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    /** Total déjà remboursé (en euros, cumulé) d'après les événements Stripe ; égal au total TTC = remboursement complet. */
    #[ORM\Column(options: ['default' => 0])]
    private float $montantRembourse = 0.0;

    /** Le stock des articles a été retiré par le système (paiement confirmé). Faux pour les anciennes commandes. */
    #[ORM\Column(options: ['default' => false])]
    private bool $stockRetire = false;

    /** L'administrateur a remis les articles en stock (jamais automatique : produits alimentaires). Irréversible. */
    #[ORM\Column(options: ['default' => false])]
    private bool $stockRestitue = false;

    /**
     * Historique des changements de statut (qui, quand, de quel statut vers quel statut), du plus ancien au plus récent.
     *
     * @var Collection<int, CommandeHistorique>
     */
    #[ORM\OneToMany(targetEntity: CommandeHistorique::class, mappedBy: 'commande', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $historique;

    public function __construct()
    {
        $this->historique = new ArrayCollection();
        $this->lignesCommande = new ArrayCollection();
        $this->dateCommande = new \DateTime();
        $this->statut = CommandeStatut::EN_ATTENTE;
    }

    // ========================================
    // GETTERS & SETTERS BASIQUES
    // ========================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumeroCommande(): ?string
    {
        return $this->numeroCommande;
    }

    public function setNumeroCommande(string $numeroCommande): static
    {
        $this->numeroCommande = $numeroCommande;
        return $this;
    }

    public function getDateCommande(): ?\DateTimeInterface
    {
        return $this->dateCommande;
    }

    public function setDateCommande(\DateTimeInterface $dateCommande): static
    {
        $this->dateCommande = $dateCommande;
        return $this;
    }

    public function getStatut(): ?CommandeStatut
    {
        return $this->statut;
    }

    public function setStatut(CommandeStatut $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    /**
     * Statut sous forme de texte, pour le composant Workflow (marking store method, propriété « statutValue »).
     * Ne pas appeler directement : passer par ChangementStatutCommande (transitions contrôlées + historique).
     */
    public function getStatutValue(): ?string
    {
        return $this->statut?->value;
    }

    public function setStatutValue(string $valeur): void
    {
        $this->statut = CommandeStatut::from($valeur);
    }

    public function getTotalTTC(): ?float
    {
        return $this->totalTTC;
    }

    public function setTotalTTC(float $totalTTC): static
    {
        $this->totalTTC = $totalTTC;
        return $this;
    }

    /**
     * Alias pour compatibilité
     */
    public function getMontantTotal(): ?float
    {
        return $this->totalTTC;
    }

    public function getDateLivraison(): ?\DateTimeInterface
    {
        return $this->dateLivraison;
    }

    public function setDateLivraison(?\DateTimeInterface $dateLivraison): static
    {
        $this->dateLivraison = $dateLivraison;
        return $this;
    }

    public function getDateExpedition(): ?\DateTimeInterface
    {
        return $this->dateExpedition;
    }

    public function setDateExpedition(?\DateTimeInterface $dateExpedition): static
    {
        $this->dateExpedition = $dateExpedition;
        return $this;
    }

    // ========================================
    // RELATIONS
    // ========================================

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return Collection<int, LigneCommande>
     */
    public function getLignesCommande(): Collection
    {
        return $this->lignesCommande;
    }

    public function addLigneCommande(LigneCommande $ligneCommande): static
    {
        if (!$this->lignesCommande->contains($ligneCommande)) {
            $this->lignesCommande->add($ligneCommande);
            $ligneCommande->setCommande($this);
        }
        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): static
    {
        if ($this->lignesCommande->removeElement($ligneCommande)) {
            if ($ligneCommande->getCommande() === $this) {
                $ligneCommande->setCommande(null);
            }
        }
        return $this;
    }

    public function getAdresseLivraison(): ?Adresse
    {
        return $this->adresseLivraison;
    }

    public function setAdresseLivraison(?Adresse $adresseLivraison): static
    {
        $this->adresseLivraison = $adresseLivraison;
        return $this;
    }

    public function getStripeSessionId(): ?string
{
    return $this->stripeSessionId;
}

public function setStripeSessionId(?string $stripeSessionId): static
{
    $this->stripeSessionId = $stripeSessionId;
    return $this;
}

    public function getModeLivraison(): ModeLivraison
    {
        return $this->modeLivraison;
    }

    public function setModeLivraison(ModeLivraison $modeLivraison): static
    {
        $this->modeLivraison = $modeLivraison;
        return $this;
    }

    public function isRetrait(): bool
    {
        return $this->modeLivraison === ModeLivraison::RETRAIT;
    }

    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }

    public function setStripePaymentIntentId(?string $id): static
    {
        $this->stripePaymentIntentId = $id;
        return $this;
    }

    public function getMontantRembourse(): float { return $this->montantRembourse; }

    public function setMontantRembourse(float $montant): static
    {
        $this->montantRembourse = $montant;
        return $this;
    }

    public function isStockRetire(): bool { return $this->stockRetire; }

    public function setStockRetire(bool $stockRetire): static
    {
        $this->stockRetire = $stockRetire;
        return $this;
    }

    public function isStockRestitue(): bool { return $this->stockRestitue; }

    public function setStockRestitue(bool $stockRestitue): static
    {
        $this->stockRestitue = $stockRestitue;
        return $this;
    }

    /** @return Collection<int, CommandeHistorique> */
    public function getHistorique(): Collection
    {
        return $this->historique;
    }

    /** Historique mis en forme (une ligne par changement), pour l'affichage dans l'administration. */
    public function getHistoriqueTexte(): string
    {
        if ($this->historique->isEmpty()) {
            return 'Aucun historique (commande antérieure à la mise en place du suivi).';
        }

        return implode("\n", array_map(static fn (CommandeHistorique $h): string => $h->__toString(), $this->historique->toArray()));
    }

    // ========================================
    // MÉTHODES MÉTIER
    // ========================================

    /**
     * Calcule le total TTC de la commande à partir des lignes
     */
    public function calculerTotal(): float
    {
        $total = 0;
        foreach ($this->lignesCommande as $ligne) {
            $total += $ligne->getSousTotal();
        }
        return $total;
    }

    /**
     * Compte le nombre total d'articles dans la commande
     */
    public function getNombreTotalArticles(): int
    {
        $total = 0;
        foreach ($this->lignesCommande as $ligne) {
            $total += $ligne->getQuantite();
        }
        return $total;
    }

    /**
     * Retourne le nombre de lignes différentes dans la commande
     */
    public function getNombreLignes(): int
    {
        return $this->lignesCommande->count();
    }

    /**
     * Retourne les premières images de produits (pour l'affichage)
     * 
     * @param int $limit Nombre maximum d'images à retourner
     * @return array<string>
     */
    public function getImagesPreview(int $limit = 3): array
    {
        $images = [];
        $count = 0;
        
        foreach ($this->lignesCommande as $ligne) {
            if ($count >= $limit) {
                break;
            }
            
            $produit = $ligne->getProduit();
            if ($produit && $produit->getImage()) {
                $images[] = $produit->getImage();
                $count++;
            }
        }
        
        return $images;
    }

    /**
     * Retourne le nombre d'images restantes (pour afficher "+X")
     */
    public function getNombreImagesRestantes(int $displayed = 3): int
    {
        $total = 0;
        
        foreach ($this->lignesCommande as $ligne) {
            if ($ligne->getProduit() && $ligne->getProduit()->getImage()) {
                $total++;
            }
        }
        
        return max(0, $total - $displayed);
    }

    /**
     * Vérifie si la commande est en cours (ni livrée, ni annulée)
     */
    public function isEnCours(): bool
    {
        return !in_array($this->statut, [
            CommandeStatut::LIVREE,
            CommandeStatut::ANNULEE,
            CommandeStatut::REMBOURSEE,
        ]);
    }

    /**
     * Vérifie si la commande peut être annulée
     */
    public function isAnnulable(): bool
    {
        return in_array($this->statut, [
            CommandeStatut::EN_ATTENTE,
            CommandeStatut::EN_PREPARATION
        ]);
    }

    /**
     * Retourne un libellé lisible du statut
     */
    public function getStatutLabel(): string
    {
        return match($this->statut) {
            CommandeStatut::EN_ATTENTE => 'En attente',
            CommandeStatut::EN_PREPARATION => 'En préparation',
            CommandeStatut::EXPEDIEE => 'Expédiée',
            CommandeStatut::LIVREE => 'Livrée',
            CommandeStatut::ANNULEE => 'Annulée',
            CommandeStatut::A_VERIFIER => 'À vérifier',
            CommandeStatut::REMBOURSEE => 'Remboursée',
            default => 'Inconnu',
        };
    }

    /**
     * Retourne la classe CSS du badge selon le statut
     */
    public function getStatutBadgeClass(): string
    {
        return match($this->statut) {
            CommandeStatut::EN_ATTENTE => 'badge-warning',
            CommandeStatut::EN_PREPARATION => 'badge-info',
            CommandeStatut::EXPEDIEE => 'badge-primary',
            CommandeStatut::LIVREE => 'badge-success',
            CommandeStatut::ANNULEE => 'badge-danger',
            CommandeStatut::A_VERIFIER => 'badge-danger',
            CommandeStatut::REMBOURSEE => 'badge-secondary',
            default => 'badge-secondary',
        };
    }

    /**
     * Retourne un nom descriptif pour la commande
     */
    public function getNomCommande(): string
    {
        $nombreProduits = $this->getNombreLignes();
        
        if ($nombreProduits === 0) {
            return 'Commande vide';
        }
        
        if ($nombreProduits === 1) {
            $ligne = $this->lignesCommande->first();
            $produit = $ligne->getProduit();
            return $produit ? $produit->getName() : 'Produit inconnu';
        }
        
        // Construire un nom à partir des premiers produits
        $noms = [];
        $count = 0;
        foreach ($this->lignesCommande as $ligne) {
            if ($count >= 2) {
                break;
            }
            $produit = $ligne->getProduit();
            if ($produit) {
                $noms[] = $produit->getName();
                $count++;
            }
        }
        
        $nom = implode(' & ', $noms);
        
        if ($nombreProduits > 2) {
            $nom .= ' (+' . ($nombreProduits - 2) . ')';
        }
        
        return $nom;
    }

    /**
     * Retourne la date de livraison prévue ou estimée
     */
    public function getDateLivraisonPrevue(): ?\DateTimeInterface
    {
        // Si date de livraison définie, la retourner
        if ($this->dateLivraison) {
            return $this->dateLivraison;
        }
        
        // Sinon, estimer selon le statut
        if ($this->dateExpedition) {
            // Livraison prévue 3 jours après expédition
            return (clone $this->dateExpedition)->modify('+3 days');
        }
        
        if ($this->statut === CommandeStatut::EN_PREPARATION) {
            // Livraison prévue 5 jours après la commande
            return (clone $this->dateCommande)->modify('+5 days');
        }
        
        return null;
    }

    /**
     * Retourne un message de livraison convivial
     */
    public function getMessageLivraison(): string
    {
        $datePrevue = $this->getDateLivraisonPrevue();
        
        if (!$datePrevue) {
            return 'Date de livraison à confirmer';
        }
        
        $maintenant = new \DateTime();
        $diff = $maintenant->diff($datePrevue);
        
        if ($diff->invert) {
            return 'Livraison en retard';
        }
        
        if ($diff->days === 0) {
            return 'Livraison prévue aujourd\'hui';
        }
        
        if ($diff->days === 1) {
            return 'Livraison prévue demain';
        }
        
        return sprintf('Livraison prévue dans %d jours', $diff->days);
    }

    /**
     * Pour l'affichage dans EasyAdmin et les formulaires
     */
    public function __toString(): string
    {
        return $this->numeroCommande ?? 'Commande #' . $this->id;
    }
}