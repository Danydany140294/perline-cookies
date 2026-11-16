<?php

namespace App\EventListener;

use App\Entity\Facture;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/** Une facture (ou un avoir) émis n'est JAMAIS modifié ni supprimé : toute tentative est refusée. */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
class FactureImmuable
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getObject() instanceof Facture) {
            throw new \LogicException('Une facture émise ne peut pas être modifiée (émettre un avoir à la place).');
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($args->getObject() instanceof Facture) {
            throw new \LogicException('Une facture émise ne peut pas être supprimée.');
        }
    }
}
