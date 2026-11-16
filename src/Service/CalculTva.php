<?php

namespace App\Service;

/**
 * Ventilation de la TVA à partir de prix TTC (calcul en centimes entiers, sans erreur d'arrondi flottant).
 *
 * - Les prix affichés au client sont TTC et ne changent pas : on en déduit le HT et la TVA, par taux.
 * - Frais de livraison : même régime que les produits. Si les articles ont plusieurs taux, les frais sont répartis
 *   au prorata de la valeur HT des articles de chaque taux (le dernier groupe reçoit le reliquat des centimes).
 * - Le HT et la TVA sont calculés sur le total de chaque taux (et non ligne par ligne), comme l'exige une facture.
 */
class CalculTva
{
    /**
     * @param list<array{ttc: int, taux: float}> $articles montant TTC total de chaque ligne (en centimes) et son taux (%)
     * @param int                                $fraisTtc frais de livraison TTC en centimes (0 si retrait ou offerts)
     *
     * @return array{ht: int, tva: int, ttc: int, detail: list<array{taux: float, ht: int, tva: int, ttc: int}>}
     */
    public function calculer(array $articles, int $fraisTtc = 0): array
    {
        $parTaux = []; // points de base (550 = 5,50 %) => TTC en centimes
        foreach ($articles as $article) {
            $bp = (int) round($article['taux'] * 100);
            $parTaux[$bp] = ($parTaux[$bp] ?? 0) + $article['ttc'];
        }
        ksort($parTaux);

        $fraisParTaux = array_fill_keys(array_keys($parTaux), 0);
        if ($fraisTtc > 0 && $parTaux !== []) {
            if (count($parTaux) === 1) {
                $fraisParTaux[array_key_first($parTaux)] = $fraisTtc;
            } else {
                $htArticles = [];
                foreach ($parTaux as $bp => $ttc) {
                    $htArticles[$bp] = $this->ht($ttc, $bp);
                }
                $totalHt = array_sum($htArticles);
                $reste = $fraisTtc;
                $cles = array_keys($parTaux);
                $derniere = end($cles);
                foreach ($cles as $bp) {
                    $part = $bp === $derniere ? $reste : (int) round($fraisTtc * $htArticles[$bp] / max(1, $totalHt));
                    $part = min($part, $reste);
                    $fraisParTaux[$bp] = $part;
                    $reste -= $part;
                }
            }
        }

        $detail = [];
        $totaux = ['ht' => 0, 'tva' => 0, 'ttc' => 0];
        foreach ($parTaux as $bp => $ttcArticles) {
            $ttc = $ttcArticles + $fraisParTaux[$bp];
            $ht = $this->ht($ttc, $bp);
            $detail[] = ['taux' => $bp / 100, 'ht' => $ht, 'tva' => $ttc - $ht, 'ttc' => $ttc];
            $totaux['ht'] += $ht;
            $totaux['tva'] += $ttc - $ht;
            $totaux['ttc'] += $ttc;
        }

        return $totaux + ['detail' => $detail];
    }

    /** HT d'un montant TTC (centimes) pour un taux en points de base, arrondi au centime le plus proche. */
    private function ht(int $ttc, int $pointsDeBase): int
    {
        $diviseur = 10000 + $pointsDeBase;

        return intdiv($ttc * 20000 + $diviseur, 2 * $diviseur);
    }
}
