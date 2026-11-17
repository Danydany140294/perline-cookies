<?php

namespace App\Service;

/**
 * Écriture CSV pour Excel en français : séparateur « ; », UTF-8 avec BOM, fins de ligne CRLF, décimales à la virgule,
 * dates jj/mm/aaaa, et protection contre l'injection de formules (une cellule TEXTE qui commence par =, +, -, @, tabulation
 * ou retour chariot est précédée d'une apostrophe : Excel la lit comme du texte et non comme une formule).
 */
class CsvEcriveur
{
    public const BOM = "\xEF\xBB\xBF";
    public const SEPARATEUR = ';';

    /** Cellule TEXTE sûre. */
    public static function texte(mixed $valeur): string
    {
        $texte = (string) ($valeur ?? '');
        if ($texte !== '' && in_array($texte[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $texte;
        }

        return $texte;
    }

    /** Nombre au format français (virgule décimale, sans séparateur de milliers) : jamais pris pour une formule. */
    public static function nombre(float|int|string|null $valeur, int $decimales = 2): string
    {
        return $valeur === null || $valeur === '' ? '' : number_format((float) $valeur, $decimales, ',', '');
    }

    public static function date(?\DateTimeInterface $date, string $format = 'd/m/Y H:i'): string
    {
        return $date?->format($format) ?? '';
    }

    /** @param list<string> $cellules déjà préparées (texte() / nombre() / date()) */
    public static function ligne(array $cellules): string
    {
        $sortie = [];
        foreach ($cellules as $cellule) {
            $cellule = (string) $cellule;
            $sortie[] = preg_match('/[;"\r\n]/', $cellule) ? '"' . str_replace('"', '""', $cellule) . '"' : $cellule;
        }

        return implode(self::SEPARATEUR, $sortie) . "\r\n";
    }
}
