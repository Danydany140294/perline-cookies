<?php

namespace App\Tests\Entity;

use App\Entity\Commande;
use App\Enum\ModeLivraison;
use PHPUnit\Framework\TestCase;

/** Période de livraison annoncée au client : mémorisée sur la commande, affichée dans le compte, l'e-mail et les CGV. */
class LivraisonEstimeeTest extends TestCase
{
    public function testLaCommandeMemoriseLaPeriodeAnnoncee(): void
    {
        $commande = (new Commande())->setLivraisonEstimee(new \DateTimeImmutable('2026-11-13'), new \DateTimeImmutable('2026-11-18'));

        $this->assertSame('Livraison estimée entre le vendredi 13 novembre et le mercredi 18 novembre', $commande->getLivraisonEstimeeTexte());
        $this->assertSame($commande->getLivraisonEstimeeTexte(), $commande->getMessageLivraison(), 'Le compte client affiche la date annoncée');
    }

    public function testSansEstimationOnNAffichePasDeFausseDate(): void
    {
        $this->assertNull((new Commande())->getLivraisonEstimeeTexte());
    }

    public function testEnRetraitLeMessageDeLivraisonNEstPasLaPeriodeColissimo(): void
    {
        $commande = (new Commande())->setModeLivraison(ModeLivraison::RETRAIT)
            ->setLivraisonEstimee(new \DateTimeImmutable('2026-11-13'), new \DateTimeImmutable('2026-11-18'));

        $this->assertStringNotContainsString('Livraison estimée entre', $commande->getMessageLivraison());
    }
}
