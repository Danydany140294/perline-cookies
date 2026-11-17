# Mise à jour de la production : de la version en ligne à `main`

> **Document PRÉPARÉ, rien n'a été exécuté.** Il servira à la toute fin du projet (après le front), avec le propriétaire. Il a été écrit sans se connecter au serveur : les noms exacts (conteneurs, volumes, fichiers) sont à **vérifier sur le serveur** avant de lancer chaque commande ; les commandes `docker compose` sont données comme modèle.

## Situation de départ (à confirmer sur le serveur)

- Site en ligne : https://perlinecookies.com, serveur Hetzner CX23 (Finlande), Docker Compose, projet dans `/root/Creaself/`.
- Version en ligne : **d'avant l'audit** (avant les chapitres 1.1 à 1.6, marque blanche, sécurité, conformité). Stripe en **clés de test**.
- Version cible : la branche `main` (tag `v1.11-conformite` ou le dernier tag publié).
- Écart important : sécurité des paiements (webhook), factures et avoirs (PDF dans `var/factures/`), TVA, comptes avec e-mail confirmé, administration (journal, rôle gestionnaire), paramètres de la boutique, allergènes, pages légales, nouvelles dépendances (Symfony 6.4.50).

**Règle d'or :** on ne touche à la production qu'avec (1) une sauvegarde complète vérifiée, (2) le propriétaire présent, (3) un créneau calme. En cas de doute à n'importe quelle étape : on s'arrête et on revient en arrière (section 12).

---

## 0. Avant le jour J (sans toucher au serveur)

- [ ] Toutes les décisions « Questions pour Dany » nécessaires à la mise en ligne sont prises (2FA de l'administration, suppression du compte de démonstration, etc.).
- [ ] La suite de tests complète est verte sur `main` (unitaires + E2E) et la CI GitHub est verte.
- [ ] Les valeurs réelles des **Paramètres de la boutique** sont prêtes (identité légale, médiateur, hébergeur, retrait, livraison) : voir `docs/INSTALLATION-NOUVEAU-CLIENT.md`.
- [ ] Le service d'e-mails transactionnel est choisi et configuré (SPF, DKIM, DMARC) : voir `docs/MISE-EN-LIGNE.md`, §11.
- [ ] Un créneau est réservé ; les clients sont prévenus si nécessaire.
- [ ] **Répétition générale** : restaurer la sauvegarde de production sur une machine de test (ou un second dossier Docker), jouer les migrations dessus, vérifier le site. Aucune migration n'est jouée en production sans cette répétition.

## 1. État des lieux (lecture seule, sur le serveur)

```bash
cd /root/Creaself
docker compose ps                       # conteneurs en marche, noms exacts
git log -1 --oneline && git status --short   # version déployée, fichiers modifiés à la main ?
docker compose exec php php bin/console doctrine:migrations:status
docker compose exec php php bin/console doctrine:migrations:list
cat .env.local 2>/dev/null | sed -E 's/=.*/=<masqué>/'   # liste des variables définies (sans les valeurs)
```

Noter : la dernière migration jouée, les variables d'environnement présentes, l'emplacement des volumes (base de données, `var/`, images), la version de PHP et de MySQL, l'espace disque libre.

**Cas à reconnaître :**
- *Toutes les migrations d'origine sont marquées « migrated »* : cas normal, on joue seulement les nouvelles (section 7).
- *Le schéma existe mais la table des migrations est vide ou incomplète* (base créée autrement) : **ne pas lancer `migrate` tel quel**. Comparer le schéma réel au schéma attendu (`doctrine:schema:update --dump-sql`), puis marquer à la main (`doctrine:migrations:version --add <Version>`) les seules migrations dont les changements existent déjà, après validation par le propriétaire.

## 2. Sauvegarde complète (obligatoire)

1. **Base de données** :
   ```bash
   docker compose exec mysql sh -c 'mysqldump --single-transaction --routines --triggers -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > /root/sauvegardes/base-AVANT-maj-$(date +%Y%m%d-%H%M).sql
   ```
   Vérifier : taille non nulle, `tail` du fichier (« Dump completed »), nombre de tables (`grep -c "CREATE TABLE"`).
2. **Fichiers de l'application** : copie du dossier `/root/Creaself/` (code, `.env*`, `compose*.yaml`) : `tar czf /root/sauvegardes/code-AVANT-maj-$(date +%Y%m%d-%H%M).tar.gz /root/Creaself`.
3. **Images envoyées** (`public/asset/images/produits/`, `public/asset/images/boxs/` et avatars) et **dossier des factures** s'il existe déjà (`var/factures/`) : inclus dans la copie ci-dessus si ce sont des dossiers du projet ; sinon les copier à part.
4. **Instantané du serveur** : snapshot Hetzner du serveur entier (console Hetzner, « Créer un instantané »). C'est le filet de sécurité ultime.
5. **Copier les sauvegardes hors du serveur** (poste du propriétaire ou stockage distant) et noter leurs noms dans le journal de la mise à jour.
6. **Tester** la restauration de la base sur une base vide (voir `docs/MISE-EN-LIGNE.md`, §12) : une sauvegarde non testée n'est pas une sauvegarde.

## 3. Mode maintenance

Objectif : plus aucune commande ni écriture pendant la mise à jour.

- Option simple : afficher une page statique « Maintenance » au niveau du serveur web / proxy (réponse 503 avec `Retry-After`), ou arrêter le conteneur web : `docker compose stop web` (nom à vérifier).
- **Vérifier avant qu'aucun paiement n'est en cours** : tableau de bord Stripe (paiements des dernières minutes). Les événements de webhook manqués pendant la coupure seront rejoués (section 9).
- Noter l'heure de début de maintenance.

## 4. Mise à jour du code

```bash
cd /root/Creaself
git fetch --tags
git status --short                      # doit être propre ; sinon noter/sauvegarder les écarts
git checkout v1.11-conformite           # ou le tag cible ; jamais une branche mouvante
docker compose build                    # reconstruit l'image avec PHP 8.4 et les dépendances
docker compose run --rm php composer install --no-dev --optimize-autoloader --no-interaction
```

(Adapter si le code est monté en volume ou copié dans l'image.) `composer.lock` fait foi : ne pas lancer `composer update` en production.

## 5. Variables d'environnement (nouvelles et à revoir)

À définir côté serveur (fichier `.env.local` ou variables de Compose), **jamais dans Git**. Modèle : `.env.example`.

| Variable | Valeur attendue |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `prod` / `0` |
| `APP_SECRET` | longue chaîne aléatoire (nouvelle) |
| `DATABASE_URL` | base de production (utilisateur à droits limités) |
| `MAILER_DSN` | **service d'envoi transactionnel réel** (plus de bac à sable Mailtrap) |
| `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY` | clés de test tant que le propriétaire n'a pas validé le passage en réel ; clés **live** ensuite |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` du point d'entrée de webhook de CET environnement (section 9) |
| `FACTURES_DIR` | dossier **persistant** (volume Docker) hors de `public/` |
| `BOUTIQUE_MODE_DEMO` | `1` pendant la validation ; `0` à l'ouverture réelle |
| `MESSENGER_TRANSPORT_DSN`, `LOCK_DSN` | voir `.env.example` |
| `LOGIN_LIMITE` | **ne pas définir** |

**Volume des factures (Docker)** : `var/factures/` (ou `FACTURES_DIR`) doit être monté sur un **volume nommé ou un dossier de l'hôte**, sinon les PDF disparaissent à la prochaine reconstruction du conteneur. Même chose pour les images envoyées depuis l'administration.

## 6. Cache et droits

```bash
docker compose run --rm php php bin/console cache:clear --env=prod
docker compose run --rm php php bin/console cache:warmup --env=prod
```
Vérifier que l'utilisateur du serveur web peut écrire dans `var/` et dans le dossier des factures et des images (et uniquement là).

## 7. Migrations (dans l'ordre, après sauvegarde)

1. `doctrine:migrations:list` : repérer les migrations « not migrated ». Elles se jouent **dans l'ordre chronologique** des numéros de version : ne jamais en sauter.
2. Lire chacune avant de la jouer. Points d'attention (état au tag `v1.11-conformite`) :

   | Migration | Effet | Précaution |
   |---|---|---|
   | `Version20260531223135` | clés étrangères | **Idempotente** : ajoute chaque clé étrangère **seulement si elle n'existe pas déjà** (la production peut déjà en avoir). Sans effet si tout existe ; son `down()` ne fait rien. Peut être jouée sans crainte même si une partie est déjà en place. |
   | `Version20261110233004` | remplace `box_produit` par `box_composition` (quantité 1 par défaut) | transforme des données : vérifier ensuite la recette de chaque box dans l'administration |
   | `Version20261111223004` | adresse de livraison copiée dans la commande, `frais_livraison` | ajoute des colonnes ; anciennes commandes sans adresse copiée |
   | `Version20261116200246` | supprime la table `moyen_paiement` | **vérifier qu'elle est vide** avant (`SELECT COUNT(*)`) |
   | `Version20261116204905` | TVA par produit et par box (5,50 % par défaut) | **à faire valider par le comptable** après coup |
   | `Version20261116235635` | e-mail confirmé (défaut « confirmé » pour les comptes existants) | les comptes existants restent connectables |
   | `Version20261117230000` | supprime `box.stock` et `produit.disponible` | **destructif** : sauvegarde obligatoire (déjà faite en section 2) |
   | autres | création de tables et ajout de colonnes | additives |

3. Jouer : `docker compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction --env=prod`.
4. Vérifier : `doctrine:schema:validate` doit répondre « in sync ». Sinon **s'arrêter** (ne pas forcer avec `schema:update --force`) et analyser l'écart.
5. Contrôler les données sensibles : nombre de produits, boxes, commandes, utilisateurs **identique à avant** (relever les comptes avant/après).

## 8. Données de la boutique après migration

- **Paramètres de la boutique** (`/admin/parametres`, avec un compte `ROLE_ADMIN`) : saisir les valeurs réelles (identité légale, hébergeur, médiateur, retrait, livraison, e-mails). Rien n'est repris automatiquement depuis l'ancienne version.
- **Allergènes** : renseigner les 14 allergènes de **chaque cookie** (l'information « non renseignée » s'affiche tant qu'ils ne le sont pas, avec invitation à contacter la boutique).
- **Compte administrateur** : `php bin/console app:promote-admin <email>` ; **supprimer le compte de démonstration** (`admin@creaself.com`) et tout compte de test.
- **Anciennes commandes de test** : les supprimer ou les annuler selon la décision du propriétaire (jamais de suppression de commande payée réelle). Les commandes d'avant l'audit n'ont pas de facture : ne pas en émettre rétroactivement sans accord du comptable (`app:factures:emettre` émet une facture pour **toute** commande payée qui n'en a pas et n'a pas d'option de simulation : **ne pas la lancer** avant d'avoir décidé avec le comptable du sort des commandes d'avant l'audit ; n'activer son cron qu'après cette décision).
- Taux de TVA des produits et des boxes : validés par le comptable.

## 9. Stripe : webhook (3 événements)

1. Tableau de bord Stripe, mode correspondant (test ou live) : Développeurs, Webhooks.
2. L'adresse du point d'entrée est `https://perlinecookies.com/webhook/stripe`. Vérifier l'existant (ancienne version) : **mettre à jour** ce point d'entrée plutôt que d'en créer un second.
3. Événements à cocher, et seulement ceux-là : `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`.
4. Copier le **secret de signature** (`whsec_…`) de CE point d'entrée dans `STRIPE_WEBHOOK_SECRET`, puis recharger la configuration (section 6).
5. Test : paiement de test (carte `4242 4242 4242 4242`), vérifier « Payée », facture, e-mail ; puis remboursement dans Stripe et avoir.
6. Événements manqués pendant la maintenance : tableau de bord Stripe, « Renvoyer », ou `php bin/console app:stripe-rejouer-evenement evt_XXX` (Stripe garde les événements 30 jours).

## 10. Messenger, tâches planifiées

- **Worker Messenger** : les e-mails partent aujourd'hui en synchrone : aucun worker permanent n'est nécessaire. Si un worker existe sur le serveur (ancienne version), le **redémarrer** après la mise à jour (`messenger:stop-workers`) ou le supprimer s'il est inutile.
- **Crons** (voir `docs/MISE-EN-LIGNE.md`, §4) : nettoyage des commandes « En attente » (`app:nettoyer-commandes-en-attente`), des paniers (`app:nettoyer-paniers`), factures manquantes (`app:factures:emettre`). Remplacer les anciens crons éventuels, sans doublon.
- **Redémarrer** les conteneurs : `docker compose up -d`.

## 11. Vérifications après mise à jour

Dans cet ordre, avec le propriétaire :

1. Sortie de maintenance (page normale), HTTPS valide, pas d'avertissement du navigateur, en-têtes de sécurité présents.
2. Accueil, catalogue (badges « Indisponible », allergènes visibles), box, panier.
3. Inscription d'un compte de test : e-mail de vérification **reçu dans une vraie boîte** (Gmail, Outlook) et **pas dans les courriers indésirables**.
4. Parcours complet : panier, adresse, paiement en retrait **et** en livraison (carte de test) ; page de paiement avec allergènes et frais ; e-mail de confirmation avec facture PDF ; facture téléchargeable depuis « Mon compte ».
5. Remboursement dans Stripe : « Remboursée » ou remboursement partiel, avoir émis.
6. Administration : tableau de bord, commande, changement de statut, export CSV, paramètres, journal ; compte gestionnaire limité.
7. Pages légales relues (hébergeur Hetzner, médiateur, dates, cookies) ; bandeau d'information.
8. Journaux : `var/log/prod.log` ou `docker compose logs --tail=200` sans erreur critique ; surveillance des erreurs active (`docs/MISE-EN-LIGNE.md`, §10).
9. Sauvegarde automatique planifiée et testée **après** la mise à jour.
10. Noter l'heure de fin de maintenance et conserver le compte rendu.

## 12. Retour arrière (si quelque chose ne va pas)

Décider vite : si le problème n'est pas compris et corrigé en 15 minutes, on revient en arrière.

1. **Remettre la page de maintenance** (section 3).
2. **Code** : `git checkout <ancien commit ou tag noté en section 1>` puis `docker compose build` et `docker compose up -d`.
3. **Base de données** : si des migrations ont été jouées, **restaurer la sauvegarde de la section 2** sur une base vide (ou remplacer la base) :
   ```bash
   docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < /root/sauvegardes/base-AVANT-maj-XXXX.sql
   ```
   Les commandes passées entre la sauvegarde et le retour arrière seraient perdues : c'est la raison du mode maintenance. Vérifier dans Stripe les paiements reçus pendant la fenêtre et les rapprocher à la main.
4. **Variables d'environnement** : remettre le fichier d'origine (sauvegardé en section 2).
5. **Stripe** : remettre l'ancien secret de webhook si le point d'entrée a été modifié.
6. **Dernier recours** : restaurer l'instantané Hetzner du serveur.
7. Vérifier que l'ancienne version fonctionne (accueil, connexion, panier), sortir de maintenance, puis analyser la cause avant de recommencer.

Les migrations ont un `down()` mais **ne pas s'en servir en production** (certaines suppriment ou recréent des colonnes sans restaurer les valeurs) : la restauration de la sauvegarde est la seule méthode de retour arrière fiable pour la base.
