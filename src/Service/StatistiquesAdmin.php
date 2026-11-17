<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\Produit;
use App\Enum\CommandeStatut;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres du tableau de bord de l'administration.
 *
 * Définitions (à valider avec le propriétaire) :
 * - « payées » = statuts payée, confirmée, en préparation, expédiée, livrée, retournée ;
 * - chiffre d'affaires = commandes payées de la période, HORS annulées, remboursées, en attente et « à vérifier », diminué du montant
 *   déjà remboursé (remboursements partiels) ; le HT est ramené au prorata du remboursement ; période = date de la commande.
 */
class StatistiquesAdmin
{
    public const STATUTS_PAYES = ['payee', 'confirmee', 'en_preparation', 'expediee', 'livree', 'retournee'];

    public function __construct(private EntityManagerInterface $em, private IdentiteBoutique $boutique)
    {
    }

    /** @return array<string, mixed> */
    public function calculer(?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $debutJour = $maintenant->setTime(0, 0);
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);

        return [
            'commandes_du_jour' => $this->commandesPayeesDepuis($debutJour),
            'en_attente_paiement' => $this->compter(['en_attente']),
            'a_verifier' => $this->em->getRepository(Commande::class)->findBy(['statut' => CommandeStatut::A_VERIFIER], ['dateCommande' => 'DESC'], 20),
            'a_preparer' => $this->compter(['payee', 'confirmee']),
            'ca_jour' => $this->chiffreAffaires($debutJour),
            'ca_mois' => $this->chiffreAffaires($debutMois),
            'stocks' => $this->alertesStock(),
            'dernieres_commandes' => $this->em->getRepository(Commande::class)->findBy([], ['dateCommande' => 'DESC', 'id' => 'DESC'], 5),
        ];
    }

    /** @param list<string> $statuts */
    private function compter(array $statuts, ?\DateTimeImmutable $depuis = null): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(c.id)')->from(Commande::class, 'c')->where('c.statut IN (:statuts)')
            ->setParameter('statuts', array_map(static fn (string $s) => CommandeStatut::from($s), $statuts));
        if ($depuis !== null) {
            $qb->andWhere('c.dateCommande >= :depuis')->setParameter('depuis', $depuis);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Commandes payées depuis cette date : la date de PAIEMENT est la date d'émission de la facture. */
    private function commandesPayeesDepuis(\DateTimeImmutable $depuis): int
    {
        $statuts = "'" . implode("','", self::STATUTS_PAYES) . "'";

        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM commande WHERE statut IN ($statuts)
               AND id IN (SELECT commande_id FROM facture WHERE type = 'facture' AND date_emission >= :depuis)",
            ['depuis' => $depuis->format('Y-m-d H:i:s')]
        );
    }

    /** @return array{ttc: float, ht: float, commandes: int, sans_ht: int} */
    private function chiffreAffaires(\DateTimeImmutable $depuis): array
    {
        $statuts = "'" . implode("','", self::STATUTS_PAYES) . "'";
        $ligne = $this->em->getConnection()->fetchAssociative(
            "SELECT COALESCE(SUM(total_ttc - montant_rembourse), 0) AS ttc,
                    COALESCE(SUM(CASE WHEN total_ht IS NOT NULL AND total_ttc > 0 THEN total_ht * (total_ttc - montant_rembourse) / total_ttc ELSE 0 END), 0) AS ht,
                    COUNT(*) AS commandes,
                    COALESCE(SUM(CASE WHEN total_ht IS NULL THEN 1 ELSE 0 END), 0) AS sans_ht
             FROM commande WHERE statut IN ($statuts)
               AND id IN (SELECT commande_id FROM facture WHERE type = 'facture' AND date_emission >= :depuis)",
            ['depuis' => $depuis->format('Y-m-d H:i:s')]
        );

        return ['ttc' => round((float) $ligne['ttc'], 2), 'ht' => round((float) $ligne['ht'], 2), 'commandes' => (int) $ligne['commandes'], 'sans_ht' => (int) $ligne['sans_ht']];
    }

    /** @return array{faibles: list<array{produit: Produit, seuil: int}>, ruptures: list<Produit>} cookies ACTIFS seulement */
    private function alertesStock(): array
    {
        $global = $this->boutique->seuilStockGlobal();
        $faibles = $ruptures = [];
        foreach ($this->em->getRepository(Produit::class)->findBy(['isActive' => true], ['name' => 'ASC']) as $produit) {
            $stock = (int) $produit->getStock();
            $seuil = $produit->getSeuilEffectif($global);
            if ($stock <= 0) {
                $ruptures[] = $produit;
            } elseif ($stock <= $seuil) {
                $faibles[] = ['produit' => $produit, 'seuil' => $seuil];
            }
        }

        return ['faibles' => $faibles, 'ruptures' => $ruptures];
    }
}
