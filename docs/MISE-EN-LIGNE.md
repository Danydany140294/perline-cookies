# Mise en ligne de la boutique : liste de contrôle (documentation uniquement)

> Ce document **ne déploie rien**. Il décrit, dans l'ordre, ce qu'il faut préparer, configurer et vérifier. À jouer une fois pour une nouvelle mise en production, et à rejouer à chaque mise à jour importante. Pour installer la boutique chez un **nouveau client**, voir d'abord `docs/INSTALLATION-NOUVEAU-CLIENT.md`.

## 0. Avant toute chose

1. Les tests passent sur la version à déployer : `php bin/phpunit` et la suite de bout en bout (`cd e2e && npm run e2e`).
2. `composer audit` ne signale plus d'avis bloquant (voir `docs/securite.md`).
3. Aucun secret n'est dans le dépôt Git (`.env.local`, clés Stripe, `whsec_`, DSN sont dans les variables d'environnement de l'hébergeur).

## 1. Variables d'environnement (hébergeur, jamais dans Git)

| Variable | Rôle | Valeur en production |
|---|---|---|
| `APP_ENV` | environnement Symfony | `prod` |
| `APP_DEBUG` | affichage des erreurs | `0` (jamais `1`) |
| `APP_SECRET` | secret de l'application (signatures, jetons CSRF, liens signés) | longue chaîne aléatoire **propre à la production** (`php -r "echo bin2hex(random_bytes(32));"`) |
| `DATABASE_URL` | connexion MySQL | base de production, utilisateur dédié aux droits limités |
| `MAILER_DSN` | envoi des e-mails | le vrai service d'envoi (SMTP/API du client) |
| `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY` | clés Stripe | clés **live** (`pk_live_…`, `sk_live_…`) |
| `STRIPE_WEBHOOK_SECRET` | secret du webhook de production (`whsec_…`) | celui du point d'entrée enregistré dans Stripe (voir §5) |
| `MESSENGER_TRANSPORT_DSN` | transport des messages (base) | voir §4 |
| `LOCK_DSN` | verrous (numérotation des factures, etc.) | `flock` (un seul serveur) ou un verrou partagé si plusieurs serveurs |
| `FACTURES_DIR` | dossier des PDF de factures et d'avoirs | un dossier **hors de `public/`**, persistant et sauvegardé (défaut : `var/factures`) |
| `BOUTIQUE_MODE_DEMO` | bandeau et filigrane « démonstration » | **`0`** en production |
| `LOGIN_LIMITE` | limite de tentatives de connexion | **ne JAMAIS la définir en production** (réservée aux tests ; défaut : 5 tentatives par 15 minutes) |

Ne jamais fixer `LOGIN_LIMITE`, ne jamais laisser `BOUTIQUE_MODE_DEMO=1`, ne jamais coller une clé Stripe dans une commande ou un fichier suivi par Git.

## 2. Base de données et migrations

1. **Sauvegarder avant** : `mysqldump --single-transaction --routines <base> > sauvegarde-AAAA-MM-JJ.sql` (et conserver la sauvegarde hors du serveur).
2. Lister : `php bin/console doctrine:migrations:list`, puis lire les migrations en attente.
3. Jouer : `php bin/console doctrine:migrations:migrate --no-interaction`. **Jamais sans sauvegarde préalable, jamais sans avoir lu la liste.** Les migrations qui suppriment des données (colonnes inutilisées) se jouent à part, après validation (voir `docs/colonnes-inutilisees.md`).
4. Vérifier : `php bin/console doctrine:schema:validate`.

## 3. Sauvegardes (à planifier)

À sauvegarder **ensemble** (une base sans ses PDF, ou l'inverse, est inutilisable pour les factures) :

- la **base de données** (tous les jours, conservée plusieurs semaines) ;
- le **dossier des factures** (`var/factures/` ou la valeur de `FACTURES_DIR`) : les PDF ne sont **jamais régénérés**, ce sont les originaux légaux, à conserver **10 ans** ;
- le dossier `public/asset/images/produits/` (images envoyées depuis l'administration) ;
- (hors Git) les variables d'environnement, dans un coffre sécurisé.

Tester une restauration au moins une fois avant la mise en ligne.

## 4. Tâches planifiées et messages

| Tâche | Commande | Fréquence conseillée |
|---|---|---|
| Nettoyage des commandes « En attente » abandonnées (plus de 48 h ; jamais les autres statuts) | `php bin/console app:nettoyer-commandes-en-attente` | toutes les heures ou chaque nuit |
| Nettoyage des paniers expirés (30 jours d'inactivité) | `php bin/console app:nettoyer-paniers` | chaque nuit |
| Factures et avoirs manquants (filet de sécurité) | `php bin/console app:factures:emettre` | chaque nuit (idempotent) |

Exemple de cron (adapter les chemins) :

```
0 3 * * * cd /chemin/du/site && php bin/console app:nettoyer-commandes-en-attente --env=prod
10 3 * * * cd /chemin/du/site && php bin/console app:nettoyer-paniers --env=prod
20 3 * * * cd /chemin/du/site && php bin/console app:factures:emettre --env=prod
```

**Worker Messenger** : aujourd'hui, les e-mails partent en **synchrone** (`SendEmailMessage: sync` dans `config/packages/messenger.yaml`) : aucun worker permanent n'est nécessaire. Si un jour on passe les e-mails en asynchrone, il faudra un worker **permanent** supervisé (systemd ou supervisor) : `php bin/console messenger:consume async --time-limit=3600 --env=prod`, redémarré à chaque déploiement (`php bin/console messenger:stop-workers`), et un suivi des messages échoués (`messenger:failed:show`).

## 5. Webhook Stripe de production

1. Dans le tableau de bord Stripe (mode **live**) : Développeurs, Webhooks, **Ajouter un point d'entrée** : `https://<domaine>/webhook/stripe`.
2. Cocher **les 3 événements** : `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`.
3. Copier le **secret de signature** (`whsec_…`) de CE point d'entrée dans `STRIPE_WEBHOOK_SECRET` côté hébergeur (jamais celui de `stripe listen`, jamais celui du mode test).
4. Faire un vrai paiement de test de faible montant, vérifier : commande « Payée », facture émise, e-mail reçu, puis rembourser dans Stripe et vérifier l'avoir.
5. Rattrapage d'un événement manqué (Stripe garde les événements 30 jours) : `php bin/console app:stripe-rejouer-evenement evt_XXX`.

## 6. Suppression des données de test

Avant d'ouvrir au public :

- supprimer **toutes** les commandes de test (y compris celles sans client), leurs factures de test, paniers, adresses, avis, utilisateurs de test (comptes issus des fixtures : **jamais** en production) ;
- recréer le compte administrateur du client (`php bin/console app:promote-admin <email>` après son inscription), et supprimer le compte administrateur de démonstration ;
- vérifier stocks, prix et taux de TVA de tous les cookies et boxes ;
- repartir d'une numérotation de factures propre (les factures de test ne doivent pas consommer la séquence réelle) : voir `docs/INSTALLATION-NOUVEAU-CLIENT.md`.

## 7. Configuration du serveur (ce qui ne relève pas du code)

- **HTTPS obligatoire** (certificat valide, redirection HTTP vers HTTPS). Les cookies de session sont `secure: auto` : ils deviennent `Secure` dès que le site est servi en HTTPS.
- **HSTS** (`Strict-Transport-Security`) : à activer sur le serveur web une fois le HTTPS validé.
- La racine web pointe sur `public/` uniquement. `var/`, `config/`, `src/`, `.env*` ne sont jamais servis.
- PHP 8.4 avec OPcache activé ; `composer install --no-dev --optimize-autoloader` ; `php bin/console cache:clear --env=prod` puis `cache:warmup` à chaque déploiement ; droits d'écriture limités à `var/` et au dossier des images.
- Pare-feu : seule l'application accède à MySQL.
- Compresser et mettre en cache les fichiers statiques (JS, CSS, images) ; en-têtes de sécurité complémentaires : voir `docs/securite.md`.

## 8. Contrôles juridiques et comptables (par le client)

- Taux de TVA des produits et des frais de livraison validés par un **comptable**.
- CGV, mentions légales, politique de confidentialité, gestion des cookies relues par un **avocat**.
- Vraies informations légales saisies dans « Paramètres de la boutique » (raison sociale, SIRET, RCS, TVA intracommunautaire, médiateur de la consommation).
- Factures conservées **10 ans** (voir §3).

## 9. Vérification finale après déploiement

1. Page d'accueil, catalogue, panier, paiement en retrait **et** en livraison.
2. Paiement réel de faible montant, e-mail de confirmation, facture PDF (sans filigrane « démonstration »).
3. Inscription d'un compte, e-mail de vérification, réinitialisation de mot de passe.
4. Administration : tableau de bord, une commande, un changement de statut, un export CSV.
5. Aucune bannière « Version de démonstration » ; `https` partout ; aucune erreur dans les journaux (`var/log/prod.log`).

## 10. Surveillance des erreurs et de la disponibilité

- **Erreurs applicatives** : brancher un service de suivi d'erreurs (Sentry, ou un équivalent auto-hébergeable comme GlitchTip) : `composer require sentry/sentry-symfony`, puis `SENTRY_DSN` en variable d'environnement (jamais dans Git) ; vérifier qu'une erreur volontaire apparaît dans le tableau de bord ; régler une alerte par e-mail sur les nouvelles erreurs. Ne jamais envoyer de données personnelles dans les rapports (désactiver ou filtrer les données de requête) et déclarer ce sous-traitant dans la politique de confidentialité s'il est activé.
- **Journaux** : en production, les journaux partent au format JSON sur la sortie d'erreur (`docker compose logs`) ; conserver 12 mois au plus (durée annoncée dans la politique de confidentialité) et limiter leur taille (pilote de journalisation de Docker avec `max-size` et `max-file`, ou `logrotate`).
- **Disponibilité** : un service de surveillance externe (UptimeRobot, Better Stack, Uptime Kuma...) qui interroge toutes les 1 à 5 minutes la page d'accueil **et** une page qui touche la base (par exemple `/produits`), avec alerte par e-mail ou SMS. Surveiller aussi la **date d'expiration du certificat HTTPS** et du nom de domaine.
- **Paiements** : dans le tableau de bord Stripe, activer les alertes d'échec de livraison des webhooks ; une commande « À vérifier » au tableau de bord d'administration est un signal à traiter le jour même.
- **Serveur** : espace disque (base, factures et journaux grossissent), mémoire, mises à jour de sécurité du système (`unattended-upgrades`), sauvegardes réussies (alerte si une sauvegarde n'a pas eu lieu).

## 11. Délivrabilité des e-mails (obligatoire avant l'ouverture)

Les e-mails du site (confirmation de compte, réinitialisation du mot de passe, confirmation de commande avec facture, expédition, alertes) sont **transactionnels** : s'ils finissent en courrier indésirable, le client ne peut pas confirmer son compte ni retrouver sa facture.

1. **Service d'envoi transactionnel** (Brevo, Mailjet, Postmark, Amazon SES, Scaleway TEM, etc.) à la place du **bac à sable** (Mailtrap ou Mailpit, qui n'envoient rien à de vrais clients). Renseigner son `MAILER_DSN` (variable d'environnement). Choisir de préférence un service dont les serveurs sont dans l'Union européenne, et le déclarer dans « Paramètres de la boutique » (champ « Service d'envoi d'e-mails ») : la politique de confidentialité le cite.
2. **Domaine d'envoi** : l'adresse d'expédition (Paramètres de la boutique : « E-mail d'expédition des messages ») doit être sur **le domaine du client** (ex. `noreply@perlinecookies.com`) et autorisée par le service d'envoi.
3. **SPF** : enregistrement TXT du domaine autorisant le service d'envoi (`v=spf1 include:<service> ~all`, un seul enregistrement SPF par domaine).
4. **DKIM** : clés fournies par le service, publiées en DNS (CNAME ou TXT) ; vérifier l'état « authentifié » dans le tableau de bord du service.
5. **DMARC** : enregistrement TXT `_dmarc.<domaine>` ; commencer par `v=DMARC1; p=none; rua=mailto:<adresse de rapports>`, observer les rapports 2 à 4 semaines, puis passer à `quarantine`, puis `reject`.
6. **Contrôle** : envoyer un e-mail de test vers Gmail et Outlook ; vérifier « Afficher l'original » (SPF, DKIM, DMARC = PASS), un score satisfaisant sur un testeur de délivrabilité (mail-tester.com par exemple), et que les e-mails n'arrivent pas dans les indésirables.
7. Surveiller les rebonds et les plaintes dans le tableau de bord du service. L'adresse d'alertes (`email_alertes`) doit être valide : elle reçoit « commande payée » et « stock faible ».

## 12. Test de restauration d'une sauvegarde (à faire AVANT l'ouverture)

Une sauvegarde jamais restaurée n'est pas une sauvegarde. À faire sur une machine de test ou dans un second dossier Docker, **jamais sur la production**.

1. Prendre la dernière sauvegarde de la base (`mysqldump`) **et** du dossier des factures (`var/factures/` ou `FACTURES_DIR`) + des images.
2. Créer une base vide et y importer la sauvegarde : `mysql -u<utilisateur> -p <base_vide> < sauvegarde.sql`.
3. Démarrer l'application contre cette base (variables d'environnement de test, `MAILER_DSN` vers un bac à sable) avec le dossier de factures restauré.
4. Vérifier : le nombre d'utilisateurs, de commandes et de factures correspond ; une facture ancienne s'ouvre (le PDF existe) ; le tableau de bord affiche les bons chiffres ; `doctrine:migrations:list` est cohérent ; `doctrine:schema:validate` répond « in sync ».
5. Mesurer **le temps** que prend la restauration et le consigner.
6. Noter la date du test et le nom de la sauvegarde utilisée ; refaire ce test après chaque changement important et au moins une fois par trimestre.
7. Conserver les sauvegardes **hors du serveur** (autre fournisseur ou stockage distant), avec une rotation (quotidiennes 14 jours, hebdomadaires 3 mois). Les factures et la base se conservent 10 ans (obligation comptable) : prévoir un archivage annuel.

## 13. Mise à jour d'une boutique déjà en ligne

Voir `docs/MISE-A-JOUR-PRODUCTION.md` (procédure pas à pas, retour arrière compris).
