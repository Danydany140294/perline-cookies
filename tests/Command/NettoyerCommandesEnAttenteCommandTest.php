<?php

namespace App\Tests\Command;

use App\Command\NettoyerCommandesEnAttenteCommand;
use App\Entity\Commande;
use App\Enum\CommandeStatut;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class NettoyerCommandesEnAttenteCommandTest extends TestCase
{
    private function commande(CommandeStatut $statut): Commande
    {
        return (new Commande())->setNumeroCommande('CMD-' . $statut->value)->setTotalTTC(10.0)->setStatut($statut);
    }

    public function testSeulesLesCommandesEnAttenteSontSupprimees(): void
    {
        $enAttente = $this->commande(CommandeStatut::EN_ATTENTE);

        // Même si la requête renvoyait par erreur d'autres statuts, ils ne doivent jamais être supprimés
        $repository = $this->createMock(CommandeRepository::class);
        $repository->method('findEnAttenteAvant')->willReturn([
            $this->commande(CommandeStatut::ANNULEE),
            $enAttente,
            $this->commande(CommandeStatut::A_VERIFIER),
            $this->commande(CommandeStatut::PAYEE),
        ]);

        $supprimees = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $o) use (&$supprimees): void { $supprimees[] = $o; });

        $tester = new CommandTester(new NettoyerCommandesEnAttenteCommand($repository, $em));
        $tester->execute(['--heures' => '48']);

        $this->assertSame([$enAttente], $supprimees);
        $this->assertStringContainsString('1 commande(s)', $tester->getDisplay());
    }

    public function testDelaiInferieurA25HeuresEstRefuse(): void
    {
        $repository = $this->createMock(CommandeRepository::class);
        $repository->expects($this->never())->method('findEnAttenteAvant');

        $tester = new CommandTester(new NettoyerCommandesEnAttenteCommand($repository, $this->createMock(EntityManagerInterface::class)));

        $this->assertSame(1, $tester->execute(['--heures' => '2']));
    }
}
