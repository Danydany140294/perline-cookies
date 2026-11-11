<?php

namespace App\Tests\Service;

use App\Service\ZoneLivraison;
use PHPUnit\Framework\TestCase;

/** France métropolitaine, hors Corse pour le moment : codes postaux 01 à 95 sauf 20 ; DOM-TOM (97, 98) et étranger exclus. */
class ZoneLivraisonTest extends TestCase
{
    /** @dataProvider desservis */
    public function testCodesPostauxDesservis(string $codePostal): void
    {
        $zone = new ZoneLivraison();

        $this->assertTrue($zone->estDesservie($codePostal, 'France'), $codePostal);
        $this->assertNull($zone->messageNonDesservie($codePostal, 'France'));
    }

    public static function desservis(): iterable
    {
        yield 'Ain (premier département)' => ['01000'];
        yield 'Hérault (Montpellier)' => ['34000'];
        yield 'Paris' => ['75001'];
        yield 'Val-d\'Oise (dernier département)' => ['95000'];
        yield 'Haute-Corse voisine : 19' => ['19000'];
        yield 'Loire-Atlantique : 21 (juste après la Corse)' => ['21000'];
        yield 'Bouches-du-Rhône' => ['13001'];
    }

    /** @dataProvider refuses */
    public function testCodesPostauxRefusesAvecUnMessageClair(string $codePostal, string $messageContient): void
    {
        $zone = new ZoneLivraison();

        $this->assertFalse($zone->estDesservie($codePostal, 'France'), $codePostal);
        $message = $zone->messageNonDesservie($codePostal, 'France');
        $this->assertStringStartsWith('Cette adresse n\'est pas desservie : ', $message);
        $this->assertStringContainsString($messageContient, $message);
    }

    public static function refuses(): iterable
    {
        yield 'Corse-du-Sud' => ['20000', 'Corse'];
        yield 'Haute-Corse' => ['20200', 'Corse'];
        yield 'Corse 20090' => ['20090', 'Corse'];
        yield 'Guadeloupe' => ['97100', 'outre-mer'];
        yield 'Réunion' => ['97400', 'outre-mer'];
        yield 'Polynésie / COM' => ['98700', 'outre-mer'];
        yield 'Monaco' => ['98000', 'outre-mer'];
        yield 'département inexistant 96' => ['96000', 'n\'existe pas'];
        yield 'département inexistant 99' => ['99000', 'n\'existe pas'];
        yield 'zéro' => ['00000', 'n\'existe pas'];
        yield 'trop court' => ['3400', '5 chiffres'];
        yield 'lettres' => ['34a00', '5 chiffres'];
        yield 'vide' => ['', '5 chiffres'];
    }

    public function testPaysEtrangerRefuse(): void
    {
        $zone = new ZoneLivraison();

        foreach (['Belgique', 'Suisse', 'Canada', 'Autre', '', null] as $pays) {
            $this->assertFalse($zone->estDesservie('34000', $pays), (string) $pays);
        }
        $this->assertStringContainsString('France métropolitaine', $zone->messageNonDesservie('34000', 'Belgique'));
    }

    public function testLePaysEstInsensibleALaCasseEtAuxEspaces(): void
    {
        $this->assertTrue((new ZoneLivraison())->estDesservie(' 34000 ', ' france '));
    }

    public function testLaCorsePeutEtreOuverteParLaConfiguration(): void
    {
        $zone = new ZoneLivraison(['zone' => ['pays' => 'France', 'departements_exclus' => ['97', '98']]]);

        $this->assertTrue($zone->estDesservie('20000', 'France'), 'Corse ouverte par la config');
        $this->assertFalse($zone->estDesservie('97100', 'France'));
    }
}
