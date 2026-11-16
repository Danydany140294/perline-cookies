<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\Facture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Facture> */
class FactureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Facture::class);
    }

    public function factureDeLaCommande(Commande $commande): ?Facture
    {
        return $this->findOneBy(['commande' => $commande, 'type' => Facture::TYPE_FACTURE]);
    }

    /** @return list<Facture> */
    public function avoirsDeLaCommande(Commande $commande): array
    {
        return $this->findBy(['commande' => $commande, 'type' => Facture::TYPE_AVOIR], ['id' => 'ASC']);
    }
}
