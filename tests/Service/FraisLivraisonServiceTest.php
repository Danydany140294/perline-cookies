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
}
