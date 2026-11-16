<?php

namespace App\Entity;

use App\Repository\FactureRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Facture ou avoir. IMMUABLE : toutes les données sont fournies à la création, il n'y a aucun setter, et un écouteur
 * Doctrine (FactureImmuable) refuse toute modification ou suppression. Le PDF est généré une seule fois, à l'émission,
 * puis stocké hors du dossier public (jamais régénéré).
 */
#[ORM\Entity(repositoryClass: FactureRepository::class)]
#[ORM\Table(name: 'facture')]
#[ORM\UniqueConstraint(name: 'uniq_facture_numero', columns: ['numero'])]
#[ORM\UniqueConstraint(name: 'uniq_facture_sequence', columns: ['type', 'annee', 'sequence'])]
class Facture
{
    public const TYPE_FACTURE = 'facture';
    public const TYPE_AVOIR = 'avoir';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $type;

    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\Column(type: 'smallint')]
    private int $annee;

    #[ORM\Column]
    private int $sequence;

    // Une commande payée ne peut pas être supprimée tant qu'elle a une facture (RESTRICT)
    #[ORM\ManyToOne(targetEntity: Commande::class, inversedBy: 'factures')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Commande $commande;

    /** Pour un avoir : la facture d'origine. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $factureOrigine;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateEmission;

    // Montants signés : négatifs pour un avoir
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalHt;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalTva;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalTtc;

    /** @var list<array{taux: float, ht: float, tva: float, ttc: float}> */
    #[ORM\Column(type: 'json')]
    private array $tvaDetail;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $lignes;

    /** @var array<string, string> identité de l'entreprise AU MOMENT de l'émission */
    #[ORM\Column(type: 'json')]
    private array $emetteur;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $client;

    /** Émise en mode démonstration : « Document de démonstration, sans valeur fiscale ». */
    #[ORM\Column(type: 'boolean')]
    private bool $demo;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cheminPdf = null;

    /** Empreinte SHA-256 du PDF : permet de vérifier qu'il n'a pas été modifié. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $empreinte = null;

    /**
     * @param list<array{taux: float, ht: float, tva: float, ttc: float}> $tvaDetail
     * @param list<array<string, mixed>>                                  $lignes
     * @param array<string, string>                                       $emetteur
     * @param array<string, string>                                       $client
     */
    public function __construct(
        string $type, string $numero, int $annee, int $sequence, Commande $commande, ?self $factureOrigine,
        \DateTimeImmutable $dateEmission, float $totalHt, float $totalTva, float $totalTtc,
        array $tvaDetail, array $lignes, array $emetteur, array $client, bool $demo
    ) {
        $this->type = $type;
        $this->numero = $numero;
        $this->annee = $annee;
        $this->sequence = $sequence;
        $this->commande = $commande;
        $this->factureOrigine = $factureOrigine;
        $this->dateEmission = $dateEmission;
        $this->totalHt = number_format($totalHt, 2, '.', '');
        $this->totalTva = number_format($totalTva, 2, '.', '');
        $this->totalTtc = number_format($totalTtc, 2, '.', '');
        $this->tvaDetail = $tvaDetail;
        $this->lignes = $lignes;
        $this->emetteur = $emetteur;
        $this->client = $client;
        $this->demo = $demo;
    }

    /** Seul moment où le chemin et l'empreinte sont posés : l'émission, avant le premier enregistrement. */
    public function attacherPdf(string $chemin, string $empreinte): void
    {
        if ($this->id !== null || $this->cheminPdf !== null) {
            throw new \LogicException('Le PDF d\'une facture émise ne peut plus être changé.');
        }
        $this->cheminPdf = $chemin;
        $this->empreinte = $empreinte;
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function estAvoir(): bool { return $this->type === self::TYPE_AVOIR; }
    public function isAvoir(): bool { return $this->estAvoir(); }
    public function getNumero(): string { return $this->numero; }
    public function getAnnee(): int { return $this->annee; }
    public function getSequence(): int { return $this->sequence; }
    public function getCommande(): Commande { return $this->commande; }
    public function getFactureOrigine(): ?self { return $this->factureOrigine; }
    public function getDateEmission(): \DateTimeImmutable { return $this->dateEmission; }
    public function getTotalHt(): float { return (float) $this->totalHt; }
    public function getTotalTva(): float { return (float) $this->totalTva; }
    public function getTotalTtc(): float { return (float) $this->totalTtc; }
    public function getTvaDetail(): array { return $this->tvaDetail; }
    public function getLignes(): array { return $this->lignes; }
    public function getEmetteur(): array { return $this->emetteur; }
    public function getClient(): array { return $this->client; }
    public function isDemo(): bool { return $this->demo; }
    public function getCheminPdf(): ?string { return $this->cheminPdf; }
    public function getEmpreinte(): ?string { return $this->empreinte; }
}
