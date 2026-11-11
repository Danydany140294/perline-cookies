<?php

namespace App\Service;

use App\Repository\CommandeRepository;

/**
 * Numéro de commande lisible, NON séquentiel (il ne révèle pas le volume de ventes) : PER-AAAAMMJJ-XXXXXX.
 *
 * - XXXXXX : 6 caractères tirés au hasard avec `random_int()` (générateur cryptographiquement sûr) dans un alphabet
 *   SANS caractères ambigus : ni 0 ni O, ni 1 ni I ni L (un client peut le dicter par téléphone sans confusion) ;
 * - unicité garantie par un index unique en base (+ nouvel essai ici en cas de collision) ;
 * - les anciens numéros (CMD-…) ne sont jamais modifiés ;
 * - la numérotation séquentielle est réservée aux factures (obligation légale, chapitre 1.4).
 */
class NumeroCommandeGenerator
{
    public const PREFIXE = 'PER';
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'; // 31 caractères : sans 0 O 1 I L
    public const LONGUEUR = 6;
    private const ESSAIS_MAX = 10;

    public function __construct(private CommandeRepository $commandeRepository)
    {
    }

    /** @throws \RuntimeException si dix tirages de suite tombent sur un numéro déjà pris (quasi impossible) */
    public function generer(?\DateTimeInterface $date = null): string
    {
        $jour = ($date ?? new \DateTimeImmutable())->format('Ymd');

        for ($essai = 1; $essai <= self::ESSAIS_MAX; $essai++) {
            $candidat = sprintf('%s-%s-%s', self::PREFIXE, $jour, $this->tirage());

            if (!$this->commandeRepository->numeroExiste($candidat)) {
                return $candidat;
            }
        }

        throw new \RuntimeException('Impossible de générer un numéro de commande unique.');
    }

    private function tirage(): string
    {
        $fin = strlen(self::ALPHABET) - 1;
        $caracteres = '';

        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $caracteres .= self::ALPHABET[random_int(0, $fin)];
        }

        return $caracteres;
    }
}
