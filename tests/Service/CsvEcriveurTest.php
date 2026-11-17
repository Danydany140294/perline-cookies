<?php

namespace App\Tests\Service;

use App\Service\CsvEcriveur;
use PHPUnit\Framework\TestCase;

class CsvEcriveurTest extends TestCase
{
    /** @dataProvider formules */
    public function testProtectionContreLInjectionDeFormules(string $valeur): void
    {
        $this->assertSame("'" . $valeur, CsvEcriveur::texte($valeur));
    }

    /** @return iterable<string, array{string}> */
    public static function formules(): iterable
    {
        yield 'égal' => ['=HYPERLINK("http://pirate.test")'];
        yield 'plus' => ['+33612345678'];
        yield 'moins' => ['-2+3'];
        yield 'arobase' => ['@SUM(A1)'];
        yield 'tabulation' => ["\t=1+1"];
        yield 'retour chariot' => ["\r=1+1"];
    }

    public function testTexteOrdinaireInchange(): void
    {
        $this->assertSame('Dupont Léa', CsvEcriveur::texte('Dupont Léa'));
        $this->assertSame('', CsvEcriveur::texte(null));
        $this->assertSame('a=b', CsvEcriveur::texte('a=b'), 'Seul le premier caractère compte');
    }

    public function testNombresAuFormatFrancaisSansPrefixeDeFormule(): void
    {
        $this->assertSame('1234,50', CsvEcriveur::nombre(1234.5));
        $this->assertSame('-5,00', CsvEcriveur::nombre(-5));
        $this->assertSame('', CsvEcriveur::nombre(null));
        $this->assertSame('5,5', CsvEcriveur::nombre(5.5, 1));
    }

    public function testLigneSeparateurPointVirguleGuillemetsEtCrlf(): void
    {
        $this->assertSame("a;\"b;c\";\"d\"\"e\";\"f\ng\"\r\n", CsvEcriveur::ligne(['a', 'b;c', 'd"e', "f\ng"]));
        $this->assertSame("\xEF\xBB\xBF", CsvEcriveur::BOM, 'UTF-8 avec BOM : accents corrects dans Excel');
    }

    public function testDate(): void
    {
        $this->assertSame('17/11/2026 09:05', CsvEcriveur::date(new \DateTimeImmutable('2026-11-17 09:05')));
        $this->assertSame('', CsvEcriveur::date(null));
    }
}
