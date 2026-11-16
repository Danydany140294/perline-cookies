<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Service\SuiviColis;
use PHPUnit\Framework\TestCase;

class SuiviColisTest extends TestCase
{
    private function service(): SuiviColis
    {
        return new SuiviColis(['transporteur' => 'Colissimo', 'suivi_url' => 'https://suivi.test/?code={numero}']);
    }

    public function testLienPourColissimoAvecNumero(): void
    {
        $c = (new Commande())->setTransporteur('Colissimo')->setNumeroSuivi(' 6a 123 456 789 ');

        $this->assertSame('6A123456789', $c->getNumeroSuivi(), 'Espaces retirés, majuscules');
        $this->assertSame('https://suivi.test/?code=6A123456789', $this->service()->lien($c));
    }

    public function testPasDeLienSansNumeroOuPourUnAutreTransporteur(): void
    {
        $this->assertNull($this->service()->lien(new Commande()));
        $this->assertNull($this->service()->lien((new Commande())->setTransporteur('DHL')->setNumeroSuivi('ABC12345')));
    }

    public function testTransporteurVideDevientNull(): void
    {
        $this->assertNull((new Commande())->setTransporteur('  ')->getTransporteur());
    }
}
