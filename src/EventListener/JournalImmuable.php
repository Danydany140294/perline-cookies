<?php

namespace App\EventListener;

use App\Entity\JournalAdmin;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/** Une ligne du journal des actions administratives n'est JAMAIS modifiée ni supprimée. */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
class JournalImmuable
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getObject() instanceof JournalAdmin) {
            throw new \LogicException('Le journal des actions administratives ne peut pas être modifié.');
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($args->getObject() instanceof JournalAdmin) {
            throw new \LogicException('Le journal des actions administratives ne peut pas être supprimé.');
        }
    }
}
