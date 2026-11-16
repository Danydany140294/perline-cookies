<?php

namespace App\Tests\Entity;

use App\Entity\Commande;
use App\Enum\CommandeStatut;
use PHPUnit\Framework\TestCase;

/** Le compte client affiche le libellé et le badge de CHAQUE statut (avant : « Inconnu » pour « Payée » et « Confirmée »). */
class CommandeStatutAffichageTest extends TestCase
{
    /** @return iterable<string, array{CommandeStatut}> */
    public static function statuts(): iterable
    {
        foreach (CommandeStatut::cases() as $statut) {
            yield $statut->value => [$statut];
        }
    }

    /** @dataProvider statuts */
    public function testChaqueStatutADesonLibelleEtSonBadge(CommandeStatut $statut): void
    {
        $commande = (new Commande())->setStatut($statut);

        $this->assertNotSame('Inconnu', $commande->getStatutLabel(), $statut->value);
        $this->assertSame($statut->getLibelle(), $commande->getStatutLabel());
        $this->assertSame($statut->getBadgeClass(), $commande->getStatutBadgeClass());
        $this->assertStringStartsWith('badge-', $commande->getStatutBadgeClass());
    }
}
