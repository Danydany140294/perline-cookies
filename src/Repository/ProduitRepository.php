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
        return $this->requete($ordre, $limite, true);
    }

    /**
     * Cookies AFFICHÉS au catalogue : tous les actifs, y compris ceux en rupture (badge « Indisponible » et achat
     * désactivé). Un cookie désactivé par l'administrateur n'est jamais affiché.
     *
     * @return list<Produit>
     */
    public function findAffichables(array $ordre = ['name' => 'ASC'], ?int $limite = null): array
    {
        return $this->requete($ordre, $limite, false);
    }

    private function requete(array $ordre, ?int $limite, bool $enStockSeulement): array
    {
        $qb = $this->createQueryBuilder('p')->where('p.isActive = true');
        if ($enStockSeulement) {
            $qb->andWhere('p.stock > 0');
        }
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
