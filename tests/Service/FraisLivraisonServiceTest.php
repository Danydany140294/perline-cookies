<?php

namespace App\Tests\Service;

use App\Service\FraisLivraisonService;
use PHPUnit\Framework\TestCase;

class FraisLivraisonServiceTest extends TestCase
{
    private FraisLivraisonService $service;

    protected function setUp(): void
    {
        $this->service = new FraisLivraisonService();
    }

    public function testSousLeSeuilLaLivraisonEstFacturee(): void
    {
        // 49,99 €
        $this->assertSame(450, $this->service->calculer(4999));
    }

    public function testPileAuSeuilLaLivraisonEstGratuite(): void
    {
        // 50,00 €
        $this->assertSame(0, $this->service->calculer(5000));
    }

    public function testAuDessusDuSeuilLaLivraisonEstGratuite(): void
    {
        $this->assertSame(0, $this->service->calculer(12000));
    }

    public function testPanierVideNeCoutePasDeFrais(): void
    {
        $this->assertSame(0, $this->service->calculer(0));
    }

    public function testRetraitEstToujoursGratuit(): void
    {
        $this->assertSame(0, $this->service->calculer(1000, FraisLivraisonService::MODE_RETRAIT));
        $this->assertSame(0, $this->service->calculer(0, FraisLivraisonService::MODE_RETRAIT));
    }

    public function testLeModeParDefautEstLaLivraison(): void
    {
        $this->assertSame(
            $this->service->calculer(1000, FraisLivraisonService::MODE_LIVRAISON),
            $this->service->calculer(1000)
        );
    }

    public function testModeInconnuEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->calculer(1000, 'drone');
    }

    public function testSousTotalNegatifEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->calculer(-1);
    }

    public function testLesMontantsViennentDeLaConfiguration(): void
    {
        $service = new FraisLivraisonService(['modes' => ['livraison' => true, 'retrait' => true], 'frais_forfait_centimes' => 700, 'seuil_gratuite_centimes' => 6000]);

        $this->assertSame(700, $service->calculer(5999));
        $this->assertSame(0, $service->calculer(6000), 'Seuil inclus');
        $this->assertSame(600, $service->seuilGratuiteCentimes() / 10);
    }

    public function testValeursParDefautDecidees(): void
    {
        $this->assertSame(450, $this->service->tarifCentimes());
        $this->assertSame(5000, $this->service->seuilGratuiteCentimes());
        $this->assertSame('Colissimo', $this->service->transporteur());
    }

    public function testModesDisponiblesSelonLaConfiguration(): void
    {
        $tous = new FraisLivraisonService(['modes' => ['livraison' => true, 'retrait' => true], 'frais_forfait_centimes' => 450, 'seuil_gratuite_centimes' => 5000]);
        $sansRetrait = new FraisLivraisonService(['modes' => ['livraison' => true, 'retrait' => false], 'frais_forfait_centimes' => 450, 'seuil_gratuite_centimes' => 5000]);
        $sansLivraison = new FraisLivraisonService(['modes' => ['livraison' => false, 'retrait' => true], 'frais_forfait_centimes' => 450, 'seuil_gratuite_centimes' => 5000]);
        $aucun = new FraisLivraisonService(['modes' => ['livraison' => false, 'retrait' => false], 'frais_forfait_centimes' => 450, 'seuil_gratuite_centimes' => 5000]);

        $this->assertSame(['livraison', 'retrait'], $tous->modesDisponibles());
        $this->assertSame(['livraison'], $sansRetrait->modesDisponibles());
        $this->assertFalse($sansRetrait->estDisponible('retrait'));
        $this->assertSame(['retrait'], $sansLivraison->modesDisponibles());
        $this->assertSame('retrait', $sansLivraison->modeParDefaut());
        $this->assertSame([], $aucun->modesDisponibles());
        $this->assertNull($aucun->modeParDefaut());
    }

    public function testLaConfigurationRealeDuProjetEstCelleDecidee(): void
    {
        $conf = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/config/services.yaml')['parameters']['livraison'];

        $this->assertSame(450, $conf['frais_forfait_centimes']);
        $this->assertSame(5000, $conf['seuil_gratuite_centimes']);
        $this->assertSame('Colissimo', $conf['transporteur']);
        $this->assertSame(['20', '97', '98'], $conf['zone']['departements_exclus']);
    }
}