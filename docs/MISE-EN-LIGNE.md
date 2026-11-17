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
