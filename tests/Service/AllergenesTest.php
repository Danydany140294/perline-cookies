<?php

namespace App\Tests\Service;

use App\Entity\Box;
use App\Entity\BoxProduit;
use App\Entity\Produit;
use App\Enum\Allergene;
use PHPUnit\Framework\TestCase;

class AllergenesTest extends TestCase
{
    public function testLesQuatorzeAllergenesReglementaires(): void
    {
        $this->assertCount(14, Allergene::cases());
        $this->assertCount(14, Allergene::choix());
        $this->assertSame('Fruits à coque', Allergene::FRUITS_A_COQUE->court());
        $this->assertSame('Œufs', Allergene::OEUFS->court());
    }

    public function testLeSetterIgnoreLesValeursInconnuesEtGardeLOrdreReglementaire(): void
    {
        $p = (new Produit())->setAllergenes(['lait', 'inconnu', 'gluten']);

        $this->assertSame(['gluten', 'lait'], $p->getAllergenes());
        $this->assertSame([], $p->setAllergenes([])->getAllergenes(), 'Aucun allergène');
        $this->assertNull($p->setAllergenes(null)->getAllergenes(), 'Non renseigné');
    }

    public function testUnionDeCookies(): void
    {
        $a = (new Produit())->setAllergenes(['gluten', 'lait']);
        $b = (new Produit())->setAllergenes(['fruits_a_coque', 'gluten']);

        $this->assertSame([Allergene::GLUTEN, Allergene::LAIT, Allergene::FRUITS_A_COQUE], Allergene::union([$a, $b]));
        $this->assertSame([], Allergene::union([(new Produit())->setAllergenes([])]));
    }

    public function testUnCookieNonRenseigneRendLUnionInconnue(): void
    {
        $this->assertNull(Allergene::union([(new Produit())->setAllergenes(['lait']), new Produit()]));
    }

    public function testAllergenesDUneBoxCalculesDepuisSesCookies(): void
    {
        $box = new Box();
        foreach ([['gluten'], ['oeufs', 'gluten']] as $liste) {
            $c = new BoxProduit();
            $c->setProduit((new Produit())->setAllergenes($liste));
            $box->addComposition($c);
        }

        $this->assertSame([Allergene::GLUTEN, Allergene::OEUFS], $box->getAllergenes());
        $c = new BoxProduit();
        $c->setProduit(new Produit());
        $box->addComposition($c);
        $this->assertNull($box->getAllergenes());
    }
}
