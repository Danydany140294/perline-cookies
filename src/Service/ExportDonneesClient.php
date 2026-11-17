<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\User;

/** Export des données personnelles d'un client (RGPD, droit d'accès et à la portabilité) : tableau prêt à être encodé en JSON. */
class ExportDonneesClient
{
    /** @return array<string, mixed> */
    public function exporter(User $user, ?\DateTimeImmutable $maintenant = null): array
    {
        return [
            'export' => [
                'genere_le' => ($maintenant ?? new \DateTimeImmutable())->format(DATE_ATOM),
                'description' => 'Données personnelles associées à votre compte client.',
            ],
            'informations' => [
                'email' => $user->getEmail(),
                'nom' => $user->getNom(),
                'prenom' => $user->getPrenom(),
                'telephone' => $user->getTelephone(),
                'date_naissance' => $user->getDateNaissance()?->format('Y-m-d'),
                'date_inscription' => $user->getDateInscription()?->format(DATE_ATOM),
                'email_confirme' => $user->isEmailVerifie(),
                'points_fidelite' => $user->getPointsFidelite(),
            ],
            'adresses' => array_map(static fn ($a): array => [
                'nom' => $a->getNom(), 'rue' => $a->getRue(), 'complement' => $a->getComplement(), 'code_postal' => $a->getCodePostal(),
                'ville' => $a->getVille(), 'pays' => $a->getPays(), 'telephone' => $a->getTelephone(), 'par_defaut' => $a->isParDefaut(),
            ], $user->getAdresses()->toArray()),
            'commandes' => array_map(fn (Commande $c): array => $this->commande($c), $user->getCommandes()->toArray()),
            'favoris' => array_map(static fn ($p): string => (string) $p->getName(), $user->getFavoris()->toArray()),
            'avis' => array_map(static fn ($a): array => [
                'produit' => $a->getProduit()?->getName(), 'note' => $a->getNote(), 'commentaire' => $a->getCommentaire(), 'date' => $a->getDateAvis()?->format('Y-m-d'),
            ], $user->getAvis()->toArray()),
        ];
    }

    /** @return array<string, mixed> */
    private function commande(Commande $c): array
    {
        return [
            'numero' => $c->getNumeroCommande(),
            'date' => $c->getDateCommande()?->format(DATE_ATOM),
            'statut' => $c->getStatutLabel(),
            'mode' => $c->isRetrait() ? 'retrait sur place' : 'livraison à domicile',
            'lignes' => array_map(static function ($l): array {
                $compo = [];
                foreach ($l->getCompositionsBox() as $x) {
                    $compo[] = $x->getQuantite() . ' × ' . $x->getProduit()?->getName();
                }

                return [
                    'article' => $l->getProduit()?->getName() ?? $l->getBox()?->getNom(), 'quantite' => $l->getQuantite(),
                    'prix_unitaire_ttc' => $l->getPrixUnitaire(), 'taux_tva' => $l->getTauxTva(), 'composition' => $compo,
                ];
            }, $c->getLignesCommande()->toArray()),
            'frais_livraison' => $c->getFraisLivraison(),
            'total_ht' => $c->getTotalHt(),
            'tva' => $c->getTvaDetail(),
            'total_ttc' => $c->getTotalTTC(),
            'montant_rembourse' => $c->getMontantRembourse(),
            'adresse_livraison' => $c->getAdresseLivraisonFigee(),
            'suivi' => ['transporteur' => $c->getTransporteur(), 'numero_suivi' => $c->getNumeroSuivi(), 'expediee_le' => $c->getDateExpedition()?->format('Y-m-d'), 'livree_le' => $c->getDateLivraison()?->format('Y-m-d')],
            'documents' => array_map(static fn ($f): array => ['numero' => $f->getNumero(), 'type' => $f->getType(), 'date' => $f->getDateEmission()->format('Y-m-d'), 'total_ttc' => $f->getTotalTtc()], $c->getFactures()->toArray()),
        ];
    }
}
