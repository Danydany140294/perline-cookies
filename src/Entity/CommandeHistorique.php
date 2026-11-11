<?php

namespace App\Entity;

use App\Enum\CommandeStatut;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne de l'historique d'une commande : un changement de statut (ou une note), avec sa date et son origine.
 * Jamais modifiée après coup : c'est la trace qui permet de comprendre ce qui s'est passé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commande_historique')]
#[ORM\Index(name: 'idx_historique_commande_date', columns: ['commande_id', 'date'])]
class CommandeHistorique
{
    public const SOURCE_CLIENT = 'client';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_WEBHOOK = 'webhook_stripe';
    public const SOURCE_RATTRAPAGE = 'rattrapage';
    public const SOURCE_SYSTEME = 'systeme';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'historique')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Commande $commande = null;

    /** Statut avant le changement (null à la création de la commande). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $ancienStatut = null;

    #[ORM\Column(length: 50)]
    private string $nouveauStatut;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $date;

    /** Origine du changement : client, admin, webhook_stripe, rattrapage, systeme. */
    #[ORM\Column(length: 30)]
    private string $source;

    /** Qui : e-mail de l'administrateur ou du client connecté (null pour un automatisme). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    public function __construct(Commande $commande, ?string $ancienStatut, string $nouveauStatut, string $source, ?string $auteur = null, ?string $note = null)
    {
        $this->commande = $commande;
        $this->ancienStatut = $ancienStatut;
        $this->nouveauStatut = $nouveauStatut;
        $this->source = $source;
        $this->auteur = $auteur;
        $this->note = $note;
        $this->date = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCommande(): ?Commande { return $this->commande; }
    public function getAncienStatut(): ?string { return $this->ancienStatut; }
    public function getNouveauStatut(): string { return $this->nouveauStatut; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getSource(): string { return $this->source; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getNote(): ?string { return $this->note; }

    private static function libelle(?string $statut): string
    {
        return $statut === null ? 'création' : (CommandeStatut::tryFrom($statut)?->getLibelle() ?? $statut);
    }

    public function __toString(): string
    {
        $changement = $this->ancienStatut === $this->nouveauStatut
            ? self::libelle($this->nouveauStatut) . ' (note)'
            : self::libelle($this->ancienStatut) . ' → ' . self::libelle($this->nouveauStatut);

        return sprintf(
            '%s — %s — %s%s%s',
            $this->date->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i'),
            $changement,
            $this->source,
            $this->auteur ? ' (' . $this->auteur . ')' : '',
            $this->note ? ' : ' . $this->note : ''
        );
    }
}
