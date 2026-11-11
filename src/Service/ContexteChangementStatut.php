<?php

namespace App\Service;

use App\Entity\CommandeHistorique;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Qui change le statut, et pourquoi : renseigné par le code qui déclenche le changement (webhook, administration,
 * rattrapage...) et lu par l'enregistreur d'historique au moment du flush. Remis à zéro entre deux requêtes.
 */
class ContexteChangementStatut implements ResetInterface
{
    private string $source = CommandeHistorique::SOURCE_SYSTEME;
    private ?string $auteur = null;
    private ?string $note = null;

    public function definir(string $source, ?string $auteur = null, ?string $note = null): void
    {
        $this->source = $source;
        $this->auteur = $auteur;
        $this->note = $note;
    }

    public function source(): string { return $this->source; }
    public function auteur(): ?string { return $this->auteur; }
    public function note(): ?string { return $this->note; }

    public function reset(): void
    {
        $this->source = CommandeHistorique::SOURCE_SYSTEME;
        $this->auteur = null;
        $this->note = null;
    }
}
