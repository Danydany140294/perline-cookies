<?php

namespace App\EventListener;

use App\Entity\Commande;
use App\Entity\CommandeHistorique;
use App\Service\ContexteChangementStatut;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;

/**
 * Écrit une ligne d'historique pour TOUT changement de statut d'une commande (création comprise), quel que soit
 * le chemin : webhook, rattrapage, formulaire de l'administration. Qui et pourquoi : ContexteChangementStatut.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class EnregistreurHistoriqueStatut
{
    public function __construct(private ContexteChangementStatut $contexte)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        /** @var EntityManagerInterface $em */
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(CommandeHistorique::class);

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof Commande && $entite->getStatut() !== null) {
                $source = $this->contexte->source() === CommandeHistorique::SOURCE_SYSTEME ? CommandeHistorique::SOURCE_CLIENT : $this->contexte->source();
                $this->ecrire($em, $uow, $meta, new CommandeHistorique($entite, null, $entite->getStatut()->value, $source, $this->contexte->auteur() ?? $entite->getUser()?->getEmail(), $this->contexte->note()));
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if (!$entite instanceof Commande) {
                continue;
            }

            $changements = $uow->getEntityChangeSet($entite);
            if (!isset($changements['statut'])) {
                continue;
            }

            // Selon le chemin de chargement, Doctrine donne l'ancien statut en énumération OU en texte brut : on normalise
            [$ancien, $nouveau] = array_map(static fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $changements['statut']);
            if ($ancien === $nouveau || $nouveau === null) {
                continue;
            }

            $this->ecrire($em, $uow, $meta, new CommandeHistorique(
                $entite,
                $ancien,
                $nouveau,
                $this->contexte->source(),
                $this->contexte->auteur(),
                $this->contexte->note()
            ));
        }
    }

    private function ecrire(EntityManagerInterface $em, UnitOfWork $uow, ClassMetadata $meta, CommandeHistorique $ligne): void
    {
        $em->persist($ligne);
        $uow->computeChangeSet($meta, $ligne);
    }
}
