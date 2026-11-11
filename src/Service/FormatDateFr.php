<?php

namespace App\Service;

/** Dates en français pour les messages clients (« vendredi 13 novembre »). */
final class FormatDateFr
{
    public static function jourMois(\DateTimeInterface $date): string
    {
        $formateur = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Europe/Paris', \IntlDateFormatter::GREGORIAN, 'EEEE d MMMM');

        return (string) $formateur->format($date);
    }

    public static function jourMoisAnnee(\DateTimeInterface $date): string
    {
        $formateur = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Europe/Paris', \IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');

        return (string) $formateur->format($date);
    }

    /** « entre le vendredi 13 novembre et le mercredi 18 novembre » (l'année n'est ajoutée que si elle change). */
    public static function periode(\DateTimeInterface $debut, \DateTimeInterface $fin): string
    {
        $memeAnnee = $debut->format('Y') === $fin->format('Y');

        return sprintf(
            'entre le %s et le %s',
            $memeAnnee ? self::jourMois($debut) : self::jourMoisAnnee($debut),
            $memeAnnee ? self::jourMois($fin) : self::jourMoisAnnee($fin)
        );
    }
}
