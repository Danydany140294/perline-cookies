<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\User;
use App\Enum\CommandeStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commande>
 */
class CommandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    /**
     * Ce numéro de commande est-il déjà pris ?
     */
    public function numeroExiste(string $numeroCommande): bool
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.numeroCommande = :numero')
            ->setParameter('numero', $numeroCommande)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Commandes « En attente » (jamais payées) créées avant la date limite.
     * Ne renvoie JAMAIS d'autre statut : ni « À vérifier », ni « Payée ».
     *
     * @return Commande[]
     */
    public function findEnAttenteAvant(\DateTimeInterface $limite): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.statut = :statut')
            ->andWhere('c.dateCommande < :limite')
            ->setParameter('statut', CommandeStatut::EN_ATTENTE)
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getResult();
    }

}