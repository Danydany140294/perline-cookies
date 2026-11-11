<?php

namespace App\Tests\Support;

use App\Service\ChangementStatutCommande;
use App\Service\ContexteChangementStatut;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Donne aux tests le VRAI workflow « commande » (config/packages/workflow.yaml) : les tests vérifient donc les
 * transitions réellement configurées, pas une copie. Aucune base de données n'est utilisée.
 * À utiliser dans une classe qui étend KernelTestCase.
 */
trait AvecWorkflow
{
    protected ContexteChangementStatut $contexteStatut;

    protected function changementStatut(): ChangementStatutCommande
    {
        /** @var KernelTestCase $this */
        if (!static::$booted) {
            static::bootKernel();
        }
        $this->contexteStatut = new ContexteChangementStatut();

        return new ChangementStatutCommande(static::getContainer()->get('workflow.registry'), $this->contexteStatut);
    }
}
