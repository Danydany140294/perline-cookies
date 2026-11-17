<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/** Identité de l'entreprise (config/boutique.yaml) : un seul point d'accès pour le code PHP. */
class IdentiteBoutique
{
    public function __construct(#[Autowire(expression: 'service("parametres_boutique").boutique()')] private array $boutique)
    {
    }

    public function get(string $cle): string
    {
        return (string) ($this->boutique[$cle] ?? '');
    }

    public function modeDemo(): bool
    {
        return (bool) ($this->boutique['mode_demo'] ?? true);
    }

    public function expediteur(): Address
    {
        return new Address($this->get('email_expediteur'), $this->get('nom_expediteur'));
    }

    /** Adresse des alertes d'administration : mail_alertes, ou l'e-mail de contact par défaut. */
    public function emailAlertes(): string
    {
        return $this->get('email_alertes') !== '' ? $this->get('email_alertes') : $this->emailContact();
    }

    /** Seuil global d'alerte de stock (cookies). */
    public function seuilStockGlobal(): int
    {
        return max(0, (int) ($this->boutique['stock_seuil_global'] ?? 5));
    }

    public function emailContact(): string
    {
        return $this->get('email_contact');
    }

    /** « 1 rue de l'Exemple, 00000 Exemple-Ville » */
    public function adresseSiege(): string
    {
        return sprintf('%s, %s %s', $this->get('adresse_rue'), $this->get('adresse_code_postal'), $this->get('adresse_ville'));
    }

    /** @return array<string, mixed> */
    public function toutes(): array
    {
        return $this->boutique;
    }
}
