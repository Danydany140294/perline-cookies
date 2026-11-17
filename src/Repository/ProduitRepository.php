<?php

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    //    /**
    //     * @return Produit[] Returns an array of Produit objects
    //     */
        /**
     * Cookies vendables : actifs (réglage de l'administrateur) ET en stock. Même règle que Produit::isDisponible().
     *
     * @return list<Produit>
     */
    public function findDisponibles(array $ordre = ['name' => 'ASC'], ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('p')->where('p.isActive = true')->andWhere('p.stock > 0');
        foreach ($ordre as $champ => $sens) {
            $qb->addOrderBy('p.' . $champ, $sens);
        }
        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return $qb->getQuery()->getResult();
    }
//    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Produit
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
