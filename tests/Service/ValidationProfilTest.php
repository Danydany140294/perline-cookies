<?php

namespace App\Tests\Service;

use App\Service\ValidationProfil;
use PHPUnit\Framework\TestCase;

class ValidationProfilTest extends TestCase
{
    private function valider(array $donnees): array
    {
        return (new ValidationProfil())->valider($donnees, new \DateTimeImmutable('2026-11-17'));
    }

    public function testDonneesValides(): void
    {
        $r = $this->valider(['nom' => " Dupont-Martin ", 'prenom' => "Anne-Sophie", 'telephone' => '+33 6 12 34 56 78', 'dateNaissance' => '1990-05-17']);

        $this->assertSame([], $r['erreurs']);
        $this->assertSame('Dupont-Martin', $r['valeurs']['nom']);
        $this->assertSame('1990-05-17', $r['valeurs']['dateNaissance']->format('Y-m-d'));
    }

    public function testTelephoneEtDateFacultatifs(): void
    {
        $r = $this->valider(['nom' => 'Durand', 'prenom' => 'Léa', 'telephone' => '', 'dateNaissance' => '']);

        $this->assertSame([], $r['erreurs']);
        $this->assertNull($r['valeurs']['telephone']);
        $this->assertNull($r['valeurs']['dateNaissance']);
    }

    /** @dataProvider invalides */
    public function testRefus(array $donnees, string $champ): void
    {
        $r = $this->valider($donnees + ['nom' => 'Durand', 'prenom' => 'Léa']);

        $this->assertArrayHasKey($champ, $r['erreurs']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalides(): iterable
    {
        yield 'nom vide' => [['nom' => '  '], 'nom'];
        yield 'nom avec balise' => [['nom' => '<script>alert(1)</script>'], 'nom'];
        yield 'nom trop long' => [['nom' => str_repeat('a', 101)], 'nom'];
        yield 'prénom avec chiffres au début' => [['prenom' => '1abc'], 'prenom'];
        yield 'téléphone invalide' => [['telephone' => '12345'], 'telephone'];
        yield 'téléphone étranger' => [['telephone' => '+44 7911 123456'], 'telephone'];
        yield 'date inexistante' => [['dateNaissance' => '2020-02-31'], 'dateNaissance'];
        yield 'date au mauvais format' => [['dateNaissance' => '17/05/1990'], 'dateNaissance'];
        yield 'date dans le futur' => [['dateNaissance' => '2030-01-01'], 'dateNaissance'];
        yield 'date trop ancienne' => [['dateNaissance' => '1850-01-01'], 'dateNaissance'];
        yield 'valeur non textuelle' => [['nom' => ['x']], 'nom'];
    }
}
