<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Délais de préparation et de livraison, calculés en JOURS OUVRÉS :
 *  1. préparation : 48 h ouvrées (2 jours ouvrés) à partir du jour de la commande (un samedi ou un dimanche compte
 *     comme le lundi suivant) ;
 *  2. expédition : le premier jour d'expédition autorisé (par défaut lundi à mercredi : un colis de produits frais ne
 *     doit pas passer le week-end en entrepôt) à partir de la fin de la préparation ;
 *  3. livraison {transporteur} : de 2 à 5 jours ouvrés après l'expédition.
 * Les jours de fermeture (congés, jours fériés) ne comptent pas. Tout est réglable dans `livraison.delais`.
 */
class DelaisLivraison
{
    public const CONFIG_PAR_DEFAUT = [
        'preparation_jours_ouvres' => 2,
        'transit_min_jours_ouvres' => 2,
        'transit_max_jours_ouvres' => 5,
        'jours_ouvres' => [1, 2, 3, 4, 5],
        'jours_expedition' => [1, 2, 3],
        'fermetures' => [],
    ];

    private int $preparation;
    private int $transitMin;
    private int $transitMax;
    /** @var list<int> */
    private array $joursOuvres;
    /** @var list<int> */
    private array $joursExpedition;
    /** @var list<array{0: string, 1: string}> plages [début, fin] au format Y-m-d */
    private array $fermetures = [];

    public function __construct(#[Autowire(expression: 'service("parametres_boutique").livraison()')] array $livraison = ['delais' => self::CONFIG_PAR_DEFAUT])
    {
        $d = ($livraison['delais'] ?? []) + self::CONFIG_PAR_DEFAUT;

        $this->preparation = max(0, (int) $d['preparation_jours_ouvres']);
        $this->transitMin = max(0, (int) $d['transit_min_jours_ouvres']);
        $this->transitMax = max($this->transitMin, (int) $d['transit_max_jours_ouvres']);
        $this->joursOuvres = $this->entiers($d['jours_ouvres'], [1, 2, 3, 4, 5]);
        $this->joursExpedition = array_values(array_intersect($this->entiers($d['jours_expedition'], [1, 2, 3]), $this->joursOuvres)) ?: $this->joursOuvres;

        foreach ((array) $d['fermetures'] as $fermeture) {
            $fermeture = trim((string) $fermeture);
            if ($fermeture === '') {
                continue;
            }
            [$debut, $fin] = array_pad(explode('/', $fermeture, 2), 2, null);
            $this->fermetures[] = [$debut, $fin ?? $debut];
        }
    }

    /** @return list<int> */
    private function entiers(mixed $valeurs, array $parDefaut): array
    {
        $liste = array_values(array_unique(array_filter(array_map('intval', array_filter((array) $valeurs, static fn ($v) => trim((string) $v) !== '')), static fn (int $n) => $n >= 1 && $n <= 7)));

        return $liste ?: $parDefaut;
    }

    /**
     * @return array{commande: \DateTimeImmutable, pret_le: \DateTimeImmutable, expedition: \DateTimeImmutable, debut: \DateTimeImmutable, fin: \DateTimeImmutable}
     */
    public function estimer(\DateTimeInterface $dateCommande): array
    {
        $jour = \DateTimeImmutable::createFromInterface($dateCommande)->setTimezone(new \DateTimeZone('Europe/Paris'))->setTime(0, 0);

        $depart = $this->prochainJourOuvre($jour);
        $pret = $this->ajouterJoursOuvres($depart, $this->preparation);
        $expedition = $this->premierJourExpedition($pret);

        return [
            'commande' => $jour,
            'pret_le' => $pret,
            'expedition' => $expedition,
            'debut' => $this->ajouterJoursOuvres($expedition, $this->transitMin),
            'fin' => $this->ajouterJoursOuvres($expedition, $this->transitMax),
        ];
    }

    /** « entre le vendredi 13 novembre et le mercredi 18 novembre » */
    public function periode(\DateTimeInterface $dateCommande): string
    {
        $e = $this->estimer($dateCommande);

        return FormatDateFr::periode($e['debut'], $e['fin']);
    }

    /** « Livraison estimée entre le vendredi 13 novembre et le mercredi 18 novembre » */
    public function phrase(\DateTimeInterface $dateCommande): string
    {
        return 'Livraison estimée ' . $this->periode($dateCommande);
    }

    /** « lundi, mardi et mercredi » : jours d'expédition, pour l'expliquer au client. */
    public function joursExpeditionTexte(): string
    {
        $noms = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $liste = array_map(static fn (int $j) => $noms[$j], $this->joursExpedition);
        $dernier = array_pop($liste);

        return $liste === [] ? $dernier : implode(', ', $liste) . ' et ' . $dernier;
    }

    public function preparationHeures(): int
    {
        return $this->preparation * 24;
    }

    public function transitMin(): int
    {
        return $this->transitMin;
    }

    public function transitMax(): int
    {
        return $this->transitMax;
    }

    public function estOuvre(\DateTimeInterface $jour): bool
    {
        return in_array((int) $jour->format('N'), $this->joursOuvres, true) && !$this->estFerme($jour);
    }

    private function estFerme(\DateTimeInterface $jour): bool
    {
        $j = $jour->format('Y-m-d');
        foreach ($this->fermetures as [$debut, $fin]) {
            if ($j >= $debut && $j <= $fin) {
                return true;
            }
        }

        return false;
    }

    private function prochainJourOuvre(\DateTimeImmutable $jour): \DateTimeImmutable
    {
        for ($i = 0; $i < 400 && !$this->estOuvre($jour); $i++) {
            $jour = $jour->modify('+1 day');
        }

        return $jour;
    }

    private function ajouterJoursOuvres(\DateTimeImmutable $jour, int $nombre): \DateTimeImmutable
    {
        for ($ajoutes = 0, $garde = 0; $ajoutes < $nombre && $garde < 800; $garde++) {
            $jour = $jour->modify('+1 day');
            if ($this->estOuvre($jour)) {
                $ajoutes++;
            }
        }

        return $jour;
    }

    private function premierJourExpedition(\DateTimeImmutable $jour): \DateTimeImmutable
    {
        for ($i = 0; $i < 400; $i++) {
            if ($this->estOuvre($jour) && in_array((int) $jour->format('N'), $this->joursExpedition, true)) {
                return $jour;
            }
            $jour = $jour->modify('+1 day');
        }

        return $jour;
    }
}
