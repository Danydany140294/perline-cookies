<?php

namespace App\EventListener;

use App\Entity\Produit;
use App\Service\AlertesAdmin;
use App\Service\IdentiteBoutique;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Alerte de stock UNE SEULE FOIS au franchissement du seuil (stock qui passe de « au moins le seuil » à « sous le seuil »),
 * quelle que soit l'origine du changement (paiement d'un client, réglage dans l'administration). Le drapeau
 * `alerteStockEnvoyee` est remis à zéro dès que le stock repasse au niveau du seuil : une nouvelle baisse alertera de nouveau.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class AlerteStockListener
{
    /** @var list<array{produit: Produit, stock: int, seuil: int}> */
    private array $aEnvoyer = [];

    public function __construct(private AlertesAdmin $alertes, private IdentiteBoutique $boutique)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        /** @var EntityManagerInterface $em */
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(Produit::class);

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if (!$entite instanceof Produit) {
                continue;
            }
            $changements = $uow->getEntityChangeSet($entite);
            if (!isset($changements['stock'])) {
                continue;
            }
            [$ancien, $nouveau] = array_map('intval', $changements['stock']);
            $seuil = $entite->getSeuilEffectif($this->boutique->seuilStockGlobal());

            if ($nouveau >= $seuil && $entite->isAlerteStockEnvoyee()) {
                $entite->setAlerteStockEnvoyee(false);
                $uow->recomputeSingleEntityChangeSet($meta, $entite);
            } elseif ($ancien >= $seuil && $nouveau < $seuil && !$entite->isAlerteStockEnvoyee() && $entite->isActive()) {
                $entite->setAlerteStockEnvoyee(true);
                $uow->recomputeSingleEntityChangeSet($meta, $entite);
                $this->aEnvoyer[] = ['produit' => $entite, 'stock' => $nouveau, 'seuil' => $seuil];
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $envois = $this->aEnvoyer;
        $this->aEnvoyer = [];
        foreach ($envois as $e) {
            $this->alertes->stockSousLeSeuil($e['produit'], $e['stock'], $e['seuil']);
        }
    }
}
