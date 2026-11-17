<?php

namespace App\Entity;

use App\Enum\CommandeStatut;
use App\Enum\ModeLivraison;
use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

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

    /**
     * Lien vers l'adresse du compte au moment de la commande (conservé pour mémoire). Si le client supprime cette adresse,
     * le lien devient NULL (ON DELETE SET NULL) : la commande, elle, garde sa COPIE ci-dessous.
     */
    #[ORM\ManyToOne(targetEntity: Adresse::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Adresse $adresseLivraison = null;

    // ── COPIE de l'adresse de livraison, figée au moment du paiement (comme les lignes de la commande) :
    //    modifier ou supprimer une adresse du compte ne change jamais une commande déjà passée.
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $livraisonDestinataire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $livraisonRue = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $livraisonComplement = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $livraisonCodePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $livraisonVille = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $livraisonPays = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $livraisonTelephone = null;

    /** Période de livraison ANNONCÉE au client au moment de la commande (jours ouvrés, voir DelaisLivraison). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $livraisonEstimeeDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $livraisonEstimeeFin = null;

    // ── Expédition et suivi du colis (renseignés par l'administrateur ; les dates se remplissent toutes seules)
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $transporteur = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Regex(pattern: '/^[A-Z0-9]{6,30}$/', message: 'Numéro de suivi invalide : 6 à 30 lettres ou chiffres, sans espace.')]
    private ?string $numeroSuivi = null;

    /** Date d'envoi de l'e-mail d'expédition (une seule fois par commande). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailExpeditionEnvoyeLe = null;

    /** @var Collection<int, Facture> factures et avoirs de la commande (lecture seule : ils ne sont jamais modifiés) */
    #[ORM\OneToMany(targetEntity: Facture::class, mappedBy: 'commande')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $factures;

    // ── TVA : montants FIGÉS au moment de la commande (null pour les commandes antérieures au chapitre 1.4)
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $totalHt = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $totalTva = null;

    /** @var list<array{taux: float, ht: float, tva: float, ttc: float}>|null détail par taux de TVA */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tvaDetail = null;

    /** Note saisie dans le formulaire d'administration pour un changement de statut (non enregistrée ici : elle va dans l'historique). */
    private ?string $noteChangementStatut = null;

    /** Frais de livraison facturés (en euros) : total TTC = articles + frais. */
    #[ORM\Column(options: ['default' => 0])]
    private float $fraisLivraison = 0.0;

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
        $this->factures = new ArrayCollection();
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

    /** Copie l'adresse du compte dans la commande (destinataire, rue, complément, code postal, ville, pays, téléphone). */
    public function figerAdresse(Adresse $adresse, ?string $destinataire, ?string $telephone = null): static
    {
        $this->adresseLivraison = $adresse;
        $this->livraisonDestinataire = $destinataire;
        $this->livraisonRue = $adresse->getRue();
        $this->livraisonComplement = $adresse->getComplement();
        $this->livraisonCodePostal = $adresse->getCodePostal();
        $this->livraisonVille = $adresse->getVille();
        $this->livraisonPays = $adresse->getPays();
        $this->livraisonTelephone = $telephone;

        return $this;
    }

    public function hasAdresseFigee(): bool
    {
        return $this->livraisonRue !== null;
    }

    /**
     * Adresse de livraison de cette commande : la COPIE figée ; pour les commandes antérieures à la copie,
     * l'adresse du compte si elle existe encore. Null en retrait sur place.
     *
     * @return array{destinataire: ?string, rue: ?string, complement: ?string, codePostal: ?string, ville: ?string, pays: ?string, telephone: ?string}|null
     */
    public function getAdresseLivraisonFigee(): ?array
    {
        if ($this->hasAdresseFigee()) {
            return [
                'destinataire' => $this->livraisonDestinataire,
                'rue' => $this->livraisonRue,
                'complement' => $this->livraisonComplement,
                'codePostal' => $this->livraisonCodePostal,
                'ville' => $this->livraisonVille,
                'pays' => $this->livraisonPays,
                'telephone' => $this->livraisonTelephone,
            ];
        }

        $a = $this->adresseLivraison;
        if ($a === null) {
            return null;
        }

        return [
            'destinataire' => $this->user?->getNomComplet(),
            'rue' => $a->getRue(),
            'complement' => $a->getComplement(),
            'codePostal' => $a->getCodePostal(),
            'ville' => $a->getVille(),
            'pays' => $a->getPays(),
            'telephone' => $a->getTelephone() ?: $this->user?->getTelephone(),
        ];
    }

    /** Adresse mise en forme (une ligne par élément), pour l'administration. */
    public function getAdresseLivraisonTexte(): string
    {
        $a = $this->getAdresseLivraisonFigee();
        if ($a === null) {
            return $this->isRetrait() ? 'Retrait sur place (pas d\'adresse)' : 'Aucune adresse enregistrée';
        }

        return implode("\n", array_filter([
            $a['destinataire'],
            $a['rue'],
            $a['complement'],
            trim(($a['codePostal'] ?? '') . ' ' . ($a['ville'] ?? '')),
            $a['pays'],
            $a['telephone'] ? 'Tél. ' . $a['telephone'] : null,
        ]));
    }

    public function setLivraisonEstimee(?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): static
    {
        $this->livraisonEstimeeDebut = $debut;
        $this->livraisonEstimeeFin = $fin;

        return $this;
    }

    public function getLivraisonEstimeeDebut(): ?\DateTimeImmutable { return $this->livraisonEstimeeDebut; }

    public function getLivraisonEstimeeFin(): ?\DateTimeImmutable { return $this->livraisonEstimeeFin; }

    /** « Livraison estimée entre le vendredi 13 novembre et le mercredi 18 novembre » (null si aucune estimation enregistrée). */
    public function getLivraisonEstimeeTexte(): ?string
    {
        if ($this->livraisonEstimeeDebut === null || $this->livraisonEstimeeFin === null) {
            return null;
        }

        return 'Livraison estimée ' . \App\Service\FormatDateFr::periode($this->livraisonEstimeeDebut, $this->livraisonEstimeeFin);
    }

    /** @return Collection<int, Facture> */
    public function getFactures(): Collection { return $this->factures; }

    /** Lignes de la commande en texte (articles, quantités, prix et TVA figés, composition des boxes) : pour l'administration. */
    public function getLignesTexte(): string
    {
        $blocs = [];
        foreach ($this->lignesCommande as $ligne) {
            $texte = sprintf('%d × %s — %s € TTC', $ligne->getQuantite(), $ligne->getDesignation(), number_format((float) $ligne->getPrixUnitaire(), 2, ',', ' '));
            if ($ligne->getTauxTva() !== null) {
                $texte .= sprintf(' (TVA %s %%)', number_format($ligne->getTauxTva(), 2, ',', ' '));
            }
            if (($compo = $ligne->getCompositionTexte()) !== '') {
                $texte .= "\n      " . str_replace("\n", "\n      ", $compo);
            }
            $blocs[] = $texte;
        }

        return implode("\n", $blocs);
    }

    public function getTotalHt(): ?float { return $this->totalHt === null ? null : (float) $this->totalHt; }

    public function getTotalTva(): ?float { return $this->totalTva === null ? null : (float) $this->totalTva; }

    /** @return list<array{taux: float, ht: float, tva: float, ttc: float}> */
    public function getTvaDetail(): array { return $this->tvaDetail ?? []; }

    /** Fige la ventilation de la TVA (calculée par CalculTva) : une facture ne doit jamais dépendre d'un taux modifié plus tard. */
    public function figerTva(float $totalHt, float $totalTva, array $detail): static
    {
        $this->totalHt = number_format($totalHt, 2, '.', '');
        $this->totalTva = number_format($totalTva, 2, '.', '');
        $this->tvaDetail = $detail;

        return $this;
    }

    public function getTransporteur(): ?string { return $this->transporteur; }

    public function setTransporteur(?string $transporteur): static
    {
        $transporteur = $transporteur !== null ? trim($transporteur) : null;
        $this->transporteur = $transporteur === '' ? null : $transporteur;

        return $this;
    }

    public function getNumeroSuivi(): ?string { return $this->numeroSuivi; }

    /** Normalisé : espaces retirés, majuscules (« 6a 123 456 » → « 6A123456 »). */
    public function setNumeroSuivi(?string $numeroSuivi): static
    {
        $numeroSuivi = $numeroSuivi !== null ? strtoupper(preg_replace('/\s+/', '', $numeroSuivi)) : null;
        $this->numeroSuivi = $numeroSuivi === '' ? null : $numeroSuivi;

        return $this;
    }

    public function getEmailExpeditionEnvoyeLe(): ?\DateTimeImmutable { return $this->emailExpeditionEnvoyeLe; }

    public function setEmailExpeditionEnvoyeLe(?\DateTimeImmutable $date): static
    {
        $this->emailExpeditionEnvoyeLe = $date;

        return $this;
    }

    public function getNoteChangementStatut(): ?string { return $this->noteChangementStatut; }

    public function setNoteChangementStatut(?string $note): static
    {
        $this->noteChangementStatut = $note;

        return $this;
    }

    public function getFraisLivraison(): float
    {
        return $this->fraisLivraison;
    }

    public function setFraisLivraison(float $fraisLivraison): static
    {
        $this->fraisLivraison = $fraisLivraison;
        return $this;
    }

    /** Total des articles (hors livraison). */
    public function getSousTotalArticles(): float
    {
        return round((float) $this->getTotalTTC() - $this->fraisLivraison, 2);
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
    /** Libellé du statut : source unique = l'énumération CommandeStatut (tous les statuts sont couverts). */
    public function getStatutLabel(): string
    {
        return $this->statut?->getLibelle() ?? 'Inconnu';
    }

    /**
     * Retourne la classe CSS du badge selon le statut (source unique = l'énumération CommandeStatut)
     */
    public function getStatutBadgeClass(): string
    {
        return $this->statut?->getBadgeClass() ?? 'badge-secondary';
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
            return \DateTime::createFromInterface($this->dateExpedition)->modify('+3 days');
        }
        
        if ($this->statut === CommandeStatut::EN_PREPARATION && $this->dateCommande !== null) {
            // Livraison prévue 5 jours après la commande
            return \DateTime::createFromInterface($this->dateCommande)->modify('+5 days');
        }
        
        return null;
    }

    /**
     * Retourne un message de livraison convivial
     */
    public function getMessageLivraison(): string
    {
        // Date annoncée au moment de la commande (nouvelles commandes) : c'est elle qui fait foi
        if ($this->getLivraisonEstimeeTexte() !== null && !$this->isRetrait()) {
            return $this->getLivraisonEstimeeTexte();
        }

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