<?php

namespace App\Tests\Service;

use App\Service\CalculTva;
use PHPUnit\Framework\TestCase;

class CalculTvaTest extends TestCase
{
    public function testUnSeulTauxLeTotalTtcNeChangeJamais(): void
    {
        $r = (new CalculTva())->calculer([['ttc' => 1299, 'taux' => 5.5], ['ttc' => 2000, 'taux' => 5.5]], 450);

        $this->assertSame(1299 + 2000 + 450, $r['ttc']);
        $this->assertSame($r['ttc'], $r['ht'] + $r['tva'], 'HT + TVA = TTC, au centime près');
        $this->assertCount(1, $r['detail']);
        $this->assertSame(5.5, $r['detail'][0]['taux']);
        $this->assertSame(3749, $r['detail'][0]['ttc']);
        $this->assertSame(3554, $r['ht']); // 3749 / 1,055 = 3553,55
        $this->assertSame(195, $r['tva']);
    }

    public function testFraisRepartisAuProrataDuHtQuandLesTauxSontMelanges(): void
    {
        // 1055 TTC à 5,5 % (HT 1000) + 1200 TTC à 20 % (HT 1000) : les frais de 600 se répartissent 300 / 300
        $r = (new CalculTva())->calculer([['ttc' => 1055, 'taux' => 5.5], ['ttc' => 1200, 'taux' => 20.0]], 600);

        $this->assertSame(1055 + 1200 + 600, $r['ttc']);
        $this->assertCount(2, $r['detail']);
        $this->assertSame(1355, $r['detail'][0]['ttc']);
        $this->assertSame(1500, $r['detail'][1]['ttc']);
        $this->assertSame($r['ttc'], $r['ht'] + $r['tva']);
        foreach ($r['detail'] as $ligne) {
            $this->assertSame($ligne['ttc'], $ligne['ht'] + $ligne['tva']);
        }
    }

    public function testLeReliquatDesCentimesNeSePerdPas(): void
    {
        $r = (new CalculTva())->calculer([['ttc' => 1000, 'taux' => 5.5], ['ttc' => 1000, 'taux' => 10.0], ['ttc' => 1000, 'taux' => 20.0]], 451);

        $this->assertSame(3451, $r['ttc']);
        $this->assertSame(3451, array_sum(array_column($r['detail'], 'ttc')));
    }

    public function testSansFraisEtTauxZero(): void
    {
        $r = (new CalculTva())->calculer([['ttc' => 500, 'taux' => 0.0]]);

        $this->assertSame(['ht' => 500, 'tva' => 0, 'ttc' => 500], ['ht' => $r['ht'], 'tva' => $r['tva'], 'ttc' => $r['ttc']]);
        $this->assertSame([], (new CalculTva())->calculer([])['detail']);
    }

    public function testArrondiAuCentimeLePlusProche(): void
    {
        // 1,00 € TTC à 5,5 % : HT = 0,9479... -> 95 centimes, TVA 5
        $r = (new CalculTva())->calculer([['ttc' => 100, 'taux' => 5.5]]);
        $this->assertSame(95, $r['ht']);
        $this->assertSame(5, $r['tva']);
    }
}
