<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\Facture;
use App\Enum\CommandeStatut;
use App\Repository\FactureRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Émission des factures (F-AAAA-000001) et des avoirs (A-AAAA-000001).
 *
 * - Numérotation strictement séquentielle, sans trou, par type et par année : le compteur est verrouillé (SELECT ... FOR UPDATE)
 *   dans la MÊME transaction que l'insertion de la facture. Si quoi que ce soit échoue, tout est annulé, y compris le numéro.
 * - Une facture est émise une seule fois par commande ; elle n'est jamais modifiée ni supprimée.
 * - Le PDF est généré une seule fois, à l'émission, et stocké hors du dossier public.
 */
class Facturation
{
    public function __construct(
        private EntityManagerInterface $em,
        private FactureRepository $factures,
        private IdentiteBoutique $boutique,
        private CalculTva $calculTva,
        private PdfFacture $pdf,
        #[Autowire(param: 'factures_dir')] private string $dossier,
    ) {
    }

    /** Une commande n'est facturable que si elle est payée et si sa TVA a été figée (commandes postérieures au chapitre 1.4). */
    public function estFacturable(Commande $commande): bool
    {
        return $commande->getTotalHt() !== null
            && $commande->getTvaDetail() !== []
            && in_array($commande->getStatut(), [
                CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE, CommandeStatut::EN_PREPARATION, CommandeStatut::EXPEDIEE,
                CommandeStatut::LIVREE, CommandeStatut::RETOURNEE, CommandeStatut::REMBOURSEE,
            ], true);
    }

    /** Émet la facture de la commande si elle n'existe pas encore. Idempotent. */
    public function emettreFacture(Commande $commande): ?Facture
    {
        if (!$this->estFacturable($commande)) {
            return null;
        }

        return $this->em->wrapInTransaction(function () use ($commande): ?Facture {
            // Verrou sur la commande : deux paiements simultanés de la même commande n'émettent qu'une facture
            $this->em->find(Commande::class, $commande->getId(), LockMode::PESSIMISTIC_WRITE);
            if (($existante = $this->factures->factureDeLaCommande($commande)) !== null) {
                return $existante;
            }

            $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
            [$annee, $sequence] = $this->prochainNumero(Facture::TYPE_FACTURE, (int) $maintenant->format('Y'));

            $lignes = [];
            foreach ($commande->getLignesCommande() as $ligne) {
                $compo = [];
                foreach ($ligne->getCompositionsBox() as $c) {
                    $compo[] = $c->getQuantite() . ' × ' . $c->getProduit()?->getName();
                }
                $lignes[] = [
                    'designation' => $ligne->getProduit()?->getName() ?? $ligne->getBox()?->getNom() ?? 'Article',
                    'detail' => implode(', ', $compo),
                    'quantite' => $ligne->getQuantite(),
                    'prix_unitaire_ttc' => $ligne->getPrixUnitaire(),
                    'taux' => $ligne->getTauxTva(),
                    'total_ttc' => round($ligne->getPrixUnitaire() * $ligne->getQuantite(), 2),
                ];
            }
            if ($commande->getFraisLivraison() > 0) {
                $lignes[] = ['designation' => 'Frais de livraison', 'detail' => 'TVA au taux des articles, répartie au prorata', 'quantite' => 1,
                    'prix_unitaire_ttc' => $commande->getFraisLivraison(), 'taux' => null, 'total_ttc' => $commande->getFraisLivraison()];
            }

            $facture = new Facture(
                Facture::TYPE_FACTURE, $this->formater('F', $annee, $sequence), $annee, $sequence, $commande, null, $maintenant,
                (float) $commande->getTotalHt(), (float) $commande->getTotalTva(), (float) $commande->getTotalTTC(),
                $commande->getTvaDetail(), $lignes, $this->emetteur(), $this->client($commande), $this->boutique->modeDemo()
            );

            $this->enregistrer($facture, null);

            return $facture;
        });
    }

    /**
     * Émet un avoir pour la part remboursée non encore couverte par un avoir (remboursements partiels successifs possibles).
     * Idempotent : un événement rejoué n'émet rien de plus.
     */
    public function emettreAvoir(Commande $commande): ?Facture
    {
        return $this->em->wrapInTransaction(function () use ($commande): ?Facture {
            $this->em->find(Commande::class, $commande->getId(), LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($commande);

            $origine = $this->factures->factureDeLaCommande($commande);
            if ($origine === null) {
                return null; // commande sans facture (antérieure au 1.4) : pas d'avoir
            }

            $dejaAvoirCentimes = 0;
            foreach ($this->factures->avoirsDeLaCommande($commande) as $avoir) {
                $dejaAvoirCentimes += (int) round(-$avoir->getTotalTtc() * 100);
            }
            $factureCentimes = (int) round($origine->getTotalTtc() * 100);
            $rembourseCentimes = min((int) round($commande->getMontantRembourse() * 100), $factureCentimes);
            $aCouvrir = $rembourseCentimes - $dejaAvoirCentimes;
            if ($aCouvrir <= 0) {
                return null;
            }

            // Répartition du montant sur les taux de la facture, au prorata du TTC de chaque taux (reliquat au dernier)
            $groupes = $origine->getTvaDetail();
            $parts = [];
            $reste = $aCouvrir;
            foreach ($groupes as $i => $g) {
                $part = $i === array_key_last($groupes) ? $reste : (int) round($aCouvrir * ($g['ttc'] * 100) / $factureCentimes);
                $part = min($part, $reste);
                $parts[] = ['ttc' => $part, 'taux' => (float) $g['taux']];
                $reste -= $part;
            }
            $calcul = $this->calculTva->calculer($parts);

            $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
            [$annee, $sequence] = $this->prochainNumero(Facture::TYPE_AVOIR, (int) $maintenant->format('Y'));
            $total = $rembourseCentimes >= $factureCentimes;

            $avoir = new Facture(
                Facture::TYPE_AVOIR, $this->formater('A', $annee, $sequence), $annee, $sequence, $commande, $origine, $maintenant,
                -$calcul['ht'] / 100, -$calcul['tva'] / 100, -$calcul['ttc'] / 100,
                array_map(static fn (array $d): array => ['taux' => $d['taux'], 'ht' => -$d['ht'] / 100, 'tva' => -$d['tva'] / 100, 'ttc' => -$d['ttc'] / 100], $calcul['detail']),
                [[
                    'designation' => ($total && $dejaAvoirCentimes === 0 ? 'Remboursement total' : 'Remboursement') . ' de la commande ' . $commande->getNumeroCommande(),
                    'detail' => 'Se rapporte à la facture ' . $origine->getNumero(), 'quantite' => 1,
                    'prix_unitaire_ttc' => -$calcul['ttc'] / 100, 'taux' => null, 'total_ttc' => -$calcul['ttc'] / 100,
                ]],
                $origine->getEmetteur(), $origine->getClient(), $this->boutique->modeDemo()
            );

            $this->enregistrer($avoir, $origine);

            return $avoir;
        });
    }

    /** Chemin absolu du PDF stocké, ou null s'il a disparu. */
    public function cheminPdf(Facture $facture): ?string
    {
        if ($facture->getCheminPdf() === null) {
            return null;
        }
        $chemin = rtrim($this->dossier, '/\\') . '/' . $facture->getCheminPdf();

        return is_file($chemin) ? $chemin : null;
    }

    private function enregistrer(Facture $facture, ?Facture $origine): void
    {
        // Le PDF est généré avec le numéro déjà réservé, puis écrit sur disque ; si l'écriture échoue, la transaction est annulée
        // (donc le numéro aussi : pas de trou).
        $contenu = $this->pdf->generer($facture, $origine);
        $relatif = $facture->getAnnee() . '/' . $facture->getNumero() . '.pdf';
        $chemin = rtrim($this->dossier, '/\\') . '/' . $relatif;
        $systeme = new Filesystem();
        if (is_file($chemin)) {
            throw new \RuntimeException('Un fichier existe déjà pour ' . $facture->getNumero() . ' : refus de l\'écraser.');
        }
        $systeme->mkdir(dirname($chemin), 0750);
        $systeme->dumpFile($chemin, $contenu);

        try {
            $facture->attacherPdf($relatif, hash('sha256', $contenu));
            $this->em->persist($facture);
            $this->em->flush();
        } catch (\Throwable $e) {
            $systeme->remove($chemin);
            throw $e;
        }
    }

    /** @return array{0: int, 1: int} [année, séquence] : numéro suivant, compteur verrouillé jusqu'à la fin de la transaction */
    private function prochainNumero(string $type, int $annee): array
    {
        $connexion = $this->em->getConnection();
        $connexion->executeStatement('INSERT IGNORE INTO facture_compteur (type, annee, derniere_sequence) VALUES (?, ?, 0)', [$type, $annee]);
        $derniere = (int) $connexion->fetchOne('SELECT derniere_sequence FROM facture_compteur WHERE type = ? AND annee = ? FOR UPDATE', [$type, $annee]);
        $suivante = $derniere + 1;
        $connexion->executeStatement('UPDATE facture_compteur SET derniere_sequence = ? WHERE type = ? AND annee = ?', [$suivante, $type, $annee]);

        return [$annee, $suivante];
    }

    private function formater(string $prefixe, int $annee, int $sequence): string
    {
        return sprintf('%s-%d-%06d', $prefixe, $annee, $sequence);
    }

    /** @return array<string, string> */
    private function emetteur(): array
    {
        $cles = ['raison_sociale', 'forme_juridique', 'capital', 'siret', 'rcs', 'tva_intracommunautaire', 'adresse_rue', 'adresse_code_postal', 'adresse_ville', 'adresse_pays', 'email_contact', 'telephone', 'site_web'];

        return array_combine($cles, array_map(fn (string $c): string => $this->boutique->get($c), $cles));
    }

    /** @return array<string, string> */
    private function client(Commande $commande): array
    {
        $adresse = $commande->getAdresseLivraisonFigee();
        $user = $commande->getUser();

        return [
            'nom' => (string) ($adresse['destinataire'] ?? null ?: $user?->getNomComplet()),
            'email' => (string) $user?->getEmail(),
            'rue' => (string) ($adresse['rue'] ?? ''),
            'complement' => (string) ($adresse['complement'] ?? ''),
            'code_postal' => (string) ($adresse['codePostal'] ?? ''),
            'ville' => (string) ($adresse['ville'] ?? ''),
            'pays' => (string) ($adresse['pays'] ?? ''),
        ];
    }
}
