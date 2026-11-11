<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\CommandeRepository;
use App\Service\CommandeService;
use App\Service\NumeroCommandeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NumeroCommandeGeneratorTest extends TestCase
{
    private function generateur(callable $existe = null): NumeroCommandeGenerator
    {
        $repository = $this->createMock(CommandeRepository::class);
        $repository->method('numeroExiste')->willReturnCallback($existe ?? static fn () => false);

        return new NumeroCommandeGenerator($repository);
    }

    public function testFormatPerDateEtSixCaracteres(): void
    {
        $numero = $this->generateur()->generer(new \DateTimeImmutable('2026-03-15'));

        $this->assertMatchesRegularExpression('/^PER-20260315-[2-9A-HJKMNP-Z]{6}$/', $numero);
    }

    public function testAlphabetSansCaracteresAmbigus(): void
    {
        $alphabet = NumeroCommandeGenerator::ALPHABET;

        $this->assertSame(31, strlen($alphabet));
        foreach (['0', 'O', '1', 'I', 'L'] as $ambigu) {
            $this->assertStringNotContainsString($ambigu, $alphabet);
        }
        $this->assertSame($alphabet, implode('', array_unique(str_split($alphabet))), 'Aucun caractère en double');
    }

    public function testLeTirageEstAleatoireEtUtiliseToutLAlphabet(): void
    {
        $generateur = $this->generateur();
        $vus = [];
        $numeros = [];

        for ($i = 0; $i < 600; $i++) {
            $numero = $generateur->generer();
            $this->assertMatchesRegularExpression('/^PER-\d{8}-[2-9A-HJKMNP-Z]{6}$/', $numero);
            $numeros[$numero] = true;
            foreach (str_split(substr($numero, -6)) as $c) {
                $vus[$c] = true;
            }
        }

        $this->assertCount(31, $vus, 'Les 31 caractères de l\'alphabet apparaissent (tirage non biaisé)');
        $this->assertGreaterThan(590, count($numeros), 'Les numéros ne se répètent pas (pas de compteur ni de motif)');
    }

    public function testNeSuitPasUnOrdreSequentiel(): void
    {
        $generateur = $this->generateur();
        $suite = [];
        for ($i = 0; $i < 20; $i++) {
            $suite[] = substr($generateur->generer(), -6);
        }
        $trie = $suite;
        sort($trie);

        $this->assertNotSame($trie, $suite, 'Ne révèle aucun volume de ventes : pas de numéro croissant');
    }

    public function testNouvelEssaiEnCasDeCollision(): void
    {
        $appels = 0;
        $generateur = $this->generateur(function () use (&$appels): bool {
            return ++$appels <= 2; // les deux premiers numéros existent déjà
        });

        $numero = $generateur->generer();

        $this->assertSame(3, $appels);
        $this->assertStringStartsWith('PER-', $numero);
    }

    public function testAbandonApresDixCollisions(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->generateur(static fn () => true)->generer();
    }

    public function testLaCreationDUneCommandeUtiliseLeGenerateur(): void
    {
        $service = new CommandeService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(CommandeRepository::class),
            $this->generateur()
        );

        $commande = $service->creerCommande(new User());

        $this->assertMatchesRegularExpression('/^PER-\d{8}-[2-9A-HJKMNP-Z]{6}$/', $commande->getNumeroCommande());
    }
}
