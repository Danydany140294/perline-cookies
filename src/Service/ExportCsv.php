<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Enum\CommandeStatut;
use Doctrine\ORM\EntityManagerInterface;

/** Exports CSV des commandes et des ventes (une ligne par article) : générateurs de lignes, filtrés par dates et statuts. */
class ExportCsv
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param bool $toutesLesCommandes false (défaut) = commandes payées seulement
     *
     * @return iterable<string> lignes CSV (en-tête comprise)
     */
    public function commandes(?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin, bool $toutesLesCommandes = false): iterable
    {
        yield CsvEcriveur::ligne(['Numéro', 'Date', 'Statut', 'Client', 'E-mail', 'Mode', 'Articles', 'Total HT', 'TVA', 'Total TTC', 'Frais de livraison', 'Remboursé', 'Transporteur', 'Numéro de suivi', 'Facture']);
        foreach ($this->commandesFiltrees($debut, $fin, $toutesLesCommandes) as $c) {
            $facture = null;
            foreach ($c->getFactures() as $document) {
                if (!$document->estAvoir()) {
                    $facture = $document->getNumero();
                }
            }
            yield CsvEcriveur::ligne([
                CsvEcriveur::texte($c->getNumeroCommande()),
                CsvEcriveur::date($c->getDateCommande()),
                CsvEcriveur::texte($c->getStatutLabel()),
                CsvEcriveur::texte($c->getUser()?->getNomComplet()),
                CsvEcriveur::texte($c->getUser()?->getEmail()),
                CsvEcriveur::texte($c->isRetrait() ? 'Retrait' : 'Livraison'),
                (string) $c->getNombreTotalArticles(),
                CsvEcriveur::nombre($c->getTotalHt()),
                CsvEcriveur::nombre($c->getTotalTva()),
                CsvEcriveur::nombre($c->getTotalTTC()),
                CsvEcriveur::nombre($c->getFraisLivraison()),
                CsvEcriveur::nombre($c->getMontantRembourse()),
                CsvEcriveur::texte($c->getTransporteur()),
                CsvEcriveur::texte($c->getNumeroSuivi()),
                CsvEcriveur::texte($facture),
            ]);
        }
    }

    /** @return iterable<string> */
    public function ventes(?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin, bool $toutesLesCommandes = false): iterable
    {
        yield CsvEcriveur::ligne(['Commande', 'Date', 'Statut', 'Article', 'Type', 'Quantité', 'Prix unitaire TTC', 'Taux TVA (%)', 'Total TTC', 'Total HT']);
        foreach ($this->commandesFiltrees($debut, $fin, $toutesLesCommandes) as $c) {
            /** @var LigneCommande $l */
            foreach ($c->getLignesCommande() as $l) {
                $total = (float) $l->getPrixUnitaire() * (int) $l->getQuantite();
                $taux = $l->getTauxTva();
                yield CsvEcriveur::ligne([
                    CsvEcriveur::texte($c->getNumeroCommande()),
                    CsvEcriveur::date($c->getDateCommande()),
                    CsvEcriveur::texte($c->getStatutLabel()),
                    CsvEcriveur::texte($l->getDesignation()),
                    CsvEcriveur::texte($l->getBox() !== null ? 'Box' : 'Cookie'),
                    (string) $l->getQuantite(),
                    CsvEcriveur::nombre($l->getPrixUnitaire()),
                    CsvEcriveur::nombre($taux),
                    CsvEcriveur::nombre($total),
                    $taux !== null ? CsvEcriveur::nombre($total / (1 + $taux / 100)) : '',
                ]);
            }
        }
    }

    /** @return iterable<Commande> */
    private function commandesFiltrees(?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin, bool $toutes): iterable
    {
        $qb = $this->em->createQueryBuilder()->select('c')->from(Commande::class, 'c')->orderBy('c.dateCommande', 'ASC')->addOrderBy('c.id', 'ASC');
        if (!$toutes) {
            $qb->andWhere('c.statut IN (:payees)')->setParameter('payees', array_map(static fn (string $s) => CommandeStatut::from($s), StatistiquesAdmin::STATUTS_PAYES));
        }
        if ($debut !== null) {
            $qb->andWhere('c.dateCommande >= :debut')->setParameter('debut', $debut->setTime(0, 0));
        }
        if ($fin !== null) {
            $qb->andWhere('c.dateCommande < :fin')->setParameter('fin', $fin->setTime(0, 0)->modify('+1 day')); // jour de fin INCLUS
        }

        return $qb->getQuery()->toIterable();
    }
}
