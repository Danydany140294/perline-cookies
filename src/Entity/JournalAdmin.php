<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des actions sensibles de l'administration (prix, stock, disponibilité, rôles, archivages, suppressions, exports).
 * NON MODIFIABLE : aucun setter, et un écouteur Doctrine (JournalImmuable) refuse toute modification ou suppression.
 */
#[ORM\Entity]
#[ORM\Table(name: 'journal_admin')]
#[ORM\Index(name: 'idx_journal_date', columns: ['date_action'])]
#[ORM\Index(name: 'idx_journal_entite', columns: ['entite', 'entite_id'])]
class JournalAdmin
{
    public const ACTION_MODIFICATION = 'modification';
    public const ACTION_DESACTIVATION = 'desactivation';
    public const ACTION_REACTIVATION = 'reactivation';
    public const ACTION_ROLES = 'roles';
    public const ACTION_SUPPRESSION = 'suppression';
    public const ACTION_EXPORT = 'export';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateAction;

    /** E-mail de l'administrateur (ou « console » pour une commande en ligne de commande). */
    #[ORM\Column(length: 180)]
    private string $auteur;

    #[ORM\Column(length: 30)]
    private string $action;

    #[ORM\Column(length: 40)]
    private string $entite;

    #[ORM\Column(nullable: true)]
    private ?int $entiteId;

    #[ORM\Column(length: 255)]
    private string $libelle;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $avant;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $apres;

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    public function __construct(string $auteur, string $action, string $entite, ?int $entiteId, string $libelle, ?array $avant = null, ?array $apres = null, ?\DateTimeImmutable $date = null)
    {
        $this->dateAction = $date ?? new \DateTimeImmutable();
        $this->auteur = $auteur;
        $this->action = $action;
        $this->entite = $entite;
        $this->entiteId = $entiteId;
        $this->libelle = mb_substr($libelle, 0, 255);
        $this->avant = $avant;
        $this->apres = $apres;
    }

    public function getId(): ?int { return $this->id; }
    public function getDateAction(): \DateTimeImmutable { return $this->dateAction; }
    public function getAuteur(): string { return $this->auteur; }
    public function getAction(): string { return $this->action; }
    public function getEntite(): string { return $this->entite; }
    public function getEntiteId(): ?int { return $this->entiteId; }
    public function getLibelle(): string { return $this->libelle; }
    public function getAvant(): ?array { return $this->avant; }
    public function getApres(): ?array { return $this->apres; }

    /** Lisible dans l'administration : « Prix : 4,86 → 5,20 ». */
    public function getResume(): string
    {
        $lignes = [];
        foreach (array_unique(array_merge(array_keys($this->avant ?? []), array_keys($this->apres ?? []))) as $cle) {
            $format = static fn (mixed $v): string => is_bool($v) ? ($v ? 'oui' : 'non') : (is_array($v) ? implode(', ', $v) : (string) ($v ?? '—'));
            $lignes[] = sprintf('%s : %s → %s', $cle, $format($this->avant[$cle] ?? null), $format($this->apres[$cle] ?? null));
        }

        return implode("\n", $lignes);
    }
}
