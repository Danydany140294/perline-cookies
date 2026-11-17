<?php

namespace App\Service;

use App\Entity\JournalAdmin;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Paramètres de la boutique : valeurs PAR DÉFAUT de config/boutique.yaml et config/services.yaml (paramètres « boutique »,
 * « livraison » et « retrait »), surchargées par ce que l'administrateur a enregistré dans l'écran « Paramètres de la boutique »
 * (table parametre_boutique). Aucun secret ici : les clés Stripe, le DSN des e-mails, le secret du webhook restent dans les
 * variables d'environnement.
 *
 * Si la table n'existe pas encore (avant migration) ou si la base est injoignable, on retombe sur les valeurs par défaut.
 */
class ParametresBoutique implements ResetInterface
{
    public const TYPE_TEXTE = 'texte';
    public const TYPE_LONG = 'long';
    public const TYPE_EMAIL = 'email';
    public const TYPE_ENTIER = 'entier';
    public const TYPE_BOOLEEN = 'booleen';
    public const TYPE_EUROS = 'euros'; // stocké en centimes
    public const TYPE_JOURS = 'jours'; // liste de jours 1 à 7
    public const TYPE_DATES = 'dates'; // liste de dates ou de plages AAAA-MM-JJ[/AAAA-MM-JJ]
    public const TYPE_URL_SUIVI = 'url_suivi';

    /**
     * Champs modifiables dans l'écran, dans l'ordre d'affichage. Clé = « parametre.chemin ». Seuls ces champs peuvent être
     * enregistrés en base : tout le reste (et tous les secrets) vient des fichiers de configuration et des variables d'environnement.
     *
     * @var array<string, array{groupe: string, label: string, type: string, requis?: bool, aide?: string}>
     */
    public const CHAMPS = [
        // Identité légale
        'boutique.nom_marque' => ['groupe' => 'Identité légale', 'label' => 'Nom de la marque', 'type' => self::TYPE_TEXTE, 'requis' => true, 'aide' => 'Affiché partout : site, e-mails, factures, administration.'],
        'boutique.raison_sociale' => ['groupe' => 'Identité légale', 'label' => 'Raison sociale', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.forme_juridique' => ['groupe' => 'Identité légale', 'label' => 'Forme juridique', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.capital' => ['groupe' => 'Identité légale', 'label' => 'Capital social', 'type' => self::TYPE_TEXTE],
        'boutique.siret' => ['groupe' => 'Identité légale', 'label' => 'SIRET', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.rcs' => ['groupe' => 'Identité légale', 'label' => 'RCS', 'type' => self::TYPE_TEXTE],
        'boutique.tva_intracommunautaire' => ['groupe' => 'Identité légale', 'label' => 'N° de TVA intracommunautaire', 'type' => self::TYPE_TEXTE],
        'boutique.adresse_rue' => ['groupe' => 'Identité légale', 'label' => 'Adresse (rue)', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.adresse_code_postal' => ['groupe' => 'Identité légale', 'label' => 'Code postal', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.adresse_ville' => ['groupe' => 'Identité légale', 'label' => 'Ville', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.adresse_pays' => ['groupe' => 'Identité légale', 'label' => 'Pays', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.directeur_publication' => ['groupe' => 'Identité légale', 'label' => 'Directeur de la publication', 'type' => self::TYPE_TEXTE],
        'boutique.mediateur' => ['groupe' => 'Identité légale', 'label' => 'Médiateur de la consommation', 'type' => self::TYPE_LONG],
        'boutique.site_web' => ['groupe' => 'Identité légale', 'label' => 'Site web', 'type' => self::TYPE_TEXTE],

        // Pages légales
        'boutique.hebergeur_nom' => ['groupe' => 'Pages légales', 'label' => 'Hébergeur : raison sociale', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.hebergeur_adresse' => ['groupe' => 'Pages légales', 'label' => 'Hébergeur : adresse', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.hebergeur_site' => ['groupe' => 'Pages légales', 'label' => 'Hébergeur : site internet', 'type' => self::TYPE_TEXTE],
        'boutique.hebergeur_pays_donnees' => ['groupe' => 'Pages légales', 'label' => 'Pays où sont stockées les données', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.service_emails' => ['groupe' => 'Pages légales', 'label' => 'Service d\'envoi d\'e-mails (sous-traitant)', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.horaires_service_client' => ['groupe' => 'Pages légales', 'label' => 'Horaires du service client', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.traces_atelier' => ['groupe' => 'Pages légales', 'label' => 'Traces possibles dans l\'atelier (allergènes)', 'type' => self::TYPE_LONG, 'aide' => 'Texte repris dans les CGV : les allergènes manipulés dans l\'atelier, même s\'ils ne figurent pas dans la recette.'],
        'boutique.date_maj_pages_legales' => ['groupe' => 'Pages légales', 'label' => 'Date de dernière mise à jour des pages légales', 'type' => self::TYPE_TEXTE, 'requis' => true, 'aide' => 'À modifier à chaque relecture (ex. 17 novembre 2026).'],

        // Contact
        'boutique.email_contact' => ['groupe' => 'Contact', 'label' => 'E-mail de contact', 'type' => self::TYPE_EMAIL, 'requis' => true],
        'boutique.email_alertes' => ['groupe' => 'Contact', 'label' => 'E-mail des alertes', 'type' => self::TYPE_EMAIL, 'aide' => 'Reçoit « commande payée » et « stock faible ». Vide = e-mail de contact.'],
        'boutique.email_expediteur' => ['groupe' => 'Contact', 'label' => 'E-mail d\'expédition des messages', 'type' => self::TYPE_EMAIL, 'requis' => true, 'aide' => 'Adresse « de la part de » (ex. noreply@votre-domaine) : doit être autorisée par votre service d\'envoi.'],
        'boutique.nom_expediteur' => ['groupe' => 'Contact', 'label' => 'Nom de l\'expéditeur des messages', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'boutique.telephone' => ['groupe' => 'Contact', 'label' => 'Téléphone', 'type' => self::TYPE_TEXTE],

        // Retrait sur place
        'retrait.lieu' => ['groupe' => 'Retrait sur place', 'label' => 'Lieu de retrait', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'retrait.adresse' => ['groupe' => 'Retrait sur place', 'label' => 'Adresse de retrait', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'retrait.horaires' => ['groupe' => 'Retrait sur place', 'label' => 'Horaires', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'retrait.message' => ['groupe' => 'Retrait sur place', 'label' => 'Message au client', 'type' => self::TYPE_LONG],

        // Livraison
        'livraison.transporteur' => ['groupe' => 'Livraison', 'label' => 'Transporteur', 'type' => self::TYPE_TEXTE, 'requis' => true],
        'livraison.suivi_url' => ['groupe' => 'Livraison', 'label' => 'Lien de suivi du colis', 'type' => self::TYPE_URL_SUIVI, 'requis' => true, 'aide' => 'Adresse https contenant {numero}, remplacé par le numéro de suivi.'],
        'livraison.frais_forfait_centimes' => ['groupe' => 'Livraison', 'label' => 'Frais de livraison (forfait TTC)', 'type' => self::TYPE_EUROS, 'requis' => true],
        'livraison.seuil_gratuite_centimes' => ['groupe' => 'Livraison', 'label' => 'Livraison offerte à partir de (articles TTC)', 'type' => self::TYPE_EUROS, 'requis' => true],
        'livraison.delais.jours_expedition' => ['groupe' => 'Livraison', 'label' => 'Jours d\'expédition', 'type' => self::TYPE_JOURS, 'requis' => true, 'aide' => '1 = lundi … 7 = dimanche, séparés par des virgules (ex. 1,2,3).'],
        'livraison.delais.fermetures' => ['groupe' => 'Livraison', 'label' => 'Jours de fermeture', 'type' => self::TYPE_DATES, 'aide' => 'Dates AAAA-MM-JJ ou plages AAAA-MM-JJ/AAAA-MM-JJ, séparées par des virgules.'],

        // Stock
        'boutique.stock_seuil_global' => ['groupe' => 'Stock', 'label' => 'Seuil d\'alerte de stock (global)', 'type' => self::TYPE_ENTIER, 'requis' => true, 'aide' => 'Une alerte est envoyée une fois quand le stock devient égal ou inférieur à ce seuil. Modifiable par cookie dans sa fiche.'],

        // Fonctions optionnelles
        'livraison.modes.livraison' => ['groupe' => 'Fonctions optionnelles', 'label' => 'Livraison à domicile', 'type' => self::TYPE_BOOLEEN],
        'livraison.modes.retrait' => ['groupe' => 'Fonctions optionnelles', 'label' => 'Retrait sur place', 'type' => self::TYPE_BOOLEEN],
        'boutique.fonctions.favoris' => ['groupe' => 'Fonctions optionnelles', 'label' => 'Favoris', 'type' => self::TYPE_BOOLEEN],
        'boutique.fonctions.avis' => ['groupe' => 'Fonctions optionnelles', 'label' => 'Avis clients', 'type' => self::TYPE_BOOLEEN],
        'boutique.fonctions.points_fidelite' => ['groupe' => 'Fonctions optionnelles', 'label' => 'Points de fidélité', 'type' => self::TYPE_BOOLEEN],

        // Mode démo
        'boutique.mode_demo' => ['groupe' => 'Mode démonstration', 'label' => 'Mode démo (bandeau et filigrane « sans valeur fiscale » sur les factures)', 'type' => self::TYPE_BOOLEEN, 'aide' => 'À DÉCOCHER pour une boutique réelle (avec BOUTIQUE_MODE_DEMO=0 sur le serveur).'],
    ];

    /** @var array<string, mixed>|null surcharges enregistrées en base (clé => valeur), chargées une seule fois */
    private ?array $surcharges = null;

    /**
     * @param array<string, mixed> $boutique  valeurs par défaut (config/boutique.yaml)
     * @param array<string, mixed> $livraison valeurs par défaut (config/services.yaml)
     * @param array<string, mixed> $retrait   valeurs par défaut (config/services.yaml)
     */
    public function __construct(
        private ?Connection $connexion,
        #[Autowire(param: 'boutique')] private array $boutique = [],
        #[Autowire(param: 'livraison')] private array $livraison = [],
        #[Autowire(param: 'retrait')] private array $retrait = [],
    ) {
    }

    public function reset(): void
    {
        $this->surcharges = null;
    }

    /** @return array<string, mixed> */
    public function boutique(): array
    {
        return $this->appliquer($this->boutique, 'boutique');
    }

    /** @return array<string, mixed> */
    public function livraison(): array
    {
        return $this->appliquer($this->livraison, 'livraison');
    }

    /** @return array<string, mixed> */
    public function retrait(): array
    {
        return $this->appliquer($this->retrait, 'retrait');
    }

    /** Valeurs par défaut (fichiers de configuration) des champs modifiables : clé => valeur normalisée. @return array<string, mixed> */
    public function valeursParDefaut(): array
    {
        $resultat = [];
        foreach (self::CHAMPS as $cle => $champ) {
            $resultat[$cle] = self::normaliser($champ['type'], $this->lire($this->base($cle), $cle));
        }

        return $resultat;
    }

    /** Valeurs EFFECTIVES (défaut + surcharges) des champs modifiables : clé => valeur normalisée. @return array<string, mixed> */
    public function valeursEffectives(): array
    {
        $resultat = [];
        foreach (self::CHAMPS as $cle => $champ) {
            $resultat[$cle] = self::normaliser($champ['type'], $this->lire($this->base($cle), $cle, $this->surchargesEnBase()));
        }

        return $resultat;
    }

    /**
     * Enregistre les valeurs saisies. Une valeur identique à la valeur par défaut supprime sa surcharge (le fichier de
     * configuration reste alors la référence). Retourne les changements (libellé => [avant, après]) ; ils sont inscrits au journal.
     *
     * @param array<string, mixed> $saisie clé => valeur
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function enregistrer(array $saisie, string $auteur, JournalAdminService $journal): array
    {
        if ($this->connexion === null) {
            throw new \LogicException('Aucune connexion à la base : paramètres non enregistrables.');
        }

        $avant = $this->valeursEffectives();
        $defauts = $this->valeursParDefaut();
        $changements = [];

        $this->connexion->beginTransaction();
        try {
            foreach (self::CHAMPS as $cle => $champ) {
                if (!array_key_exists($cle, $saisie)) {
                    continue;
                }
                $valeur = self::normaliser($champ['type'], $saisie[$cle]);
                if ($valeur === $defauts[$cle]) {
                    $this->connexion->executeStatement('DELETE FROM parametre_boutique WHERE cle = :cle', ['cle' => $cle]);
                } else {
                    $this->connexion->executeStatement(
                        'INSERT INTO parametre_boutique (cle, valeur, modifie_le) VALUES (:cle, :valeur, :maintenant)
                         ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), modifie_le = VALUES(modifie_le)',
                        ['cle' => $cle, 'valeur' => json_encode($valeur, JSON_UNESCAPED_UNICODE), 'maintenant' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
                    );
                }
                if ($valeur !== $avant[$cle]) {
                    $changements[$champ['label']] = [$avant[$cle], $valeur];
                }
            }
            $this->connexion->commit();
        } catch (\Throwable $e) {
            $this->connexion->rollBack();
            throw $e;
        }
        $this->reset();

        if ($changements !== []) {
            $journal->journaliser(
                $auteur,
                JournalAdmin::ACTION_PARAMETRES,
                'ParametresBoutique',
                null,
                'Paramètres de la boutique',
                array_map(static fn (array $c): mixed => $c[0], $changements),
                array_map(static fn (array $c): mixed => $c[1], $changements),
            );
        }

        return $changements;
    }

    /** Valeur « propre » pour la comparaison et le stockage (types stables). */
    public static function normaliser(string $type, mixed $valeur): mixed
    {
        return match ($type) {
            self::TYPE_BOOLEEN => (bool) $valeur,
            self::TYPE_ENTIER => (int) $valeur,
            self::TYPE_EUROS => (int) round((float) $valeur),
            self::TYPE_JOURS, self::TYPE_DATES => self::liste($valeur),
            default => is_string($valeur) ? trim($valeur) : (string) ($valeur ?? ''),
        };
    }

    /** @return list<string> */
    private static function liste(mixed $valeur): array
    {
        if (is_string($valeur)) {
            $valeur = explode(',', $valeur);
        }
        $liste = [];
        foreach ((array) $valeur as $element) {
            $element = trim((string) $element);
            if ($element !== '') {
                $liste[] = $element;
            }
        }

        return $liste;
    }

    /** @return array<string, mixed> */
    private function surchargesEnBase(): array
    {
        if ($this->surcharges !== null) {
            return $this->surcharges;
        }
        $this->surcharges = [];
        if ($this->connexion === null) {
            return $this->surcharges;
        }
        try {
            foreach ($this->connexion->fetchAllAssociative('SELECT cle, valeur FROM parametre_boutique') as $ligne) {
                if (isset(self::CHAMPS[$ligne['cle']])) { // une clé inconnue n'est jamais appliquée
                    $this->surcharges[$ligne['cle']] = json_decode((string) $ligne['valeur'], true);
                }
            }
        } catch (\Throwable) {
            $this->surcharges = []; // table absente (avant migration) ou base injoignable : valeurs par défaut
        }

        return $this->surcharges;
    }

    /** @param array<string, mixed> $base @return array<string, mixed> */
    private function appliquer(array $base, string $parametre): array
    {
        foreach ($this->surchargesEnBase() as $cle => $valeur) {
            if (!str_starts_with($cle, $parametre . '.')) {
                continue;
            }
            $chemin = explode('.', substr($cle, strlen($parametre) + 1));
            $cible = &$base;
            foreach ($chemin as $i => $segment) {
                if ($i === count($chemin) - 1) {
                    $cible[$segment] = $valeur;
                } else {
                    if (!isset($cible[$segment]) || !is_array($cible[$segment])) {
                        $cible[$segment] = [];
                    }
                    $cible = &$cible[$segment];
                }
            }
            unset($cible);
        }

        return $base;
    }

    /** @return array<string, mixed> le tableau par défaut dont dépend cette clé */
    private function base(string $cle): array
    {
        return match (explode('.', $cle)[0]) {
            'livraison' => $this->livraison,
            'retrait' => $this->retrait,
            default => $this->boutique,
        };
    }

    /** @param array<string, mixed> $base */
    private function lire(array $base, string $cle, array $surcharges = []): mixed
    {
        if (array_key_exists($cle, $surcharges)) {
            return $surcharges[$cle];
        }
        $courant = $base;
        foreach (array_slice(explode('.', $cle), 1) as $segment) {
            if (!is_array($courant) || !array_key_exists($segment, $courant)) {
                return null;
            }
            $courant = $courant[$segment];
        }

        return $courant;
    }
}
