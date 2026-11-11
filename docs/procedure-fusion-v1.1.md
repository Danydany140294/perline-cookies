# Procédure de fusion dans `main` : chapitre 1.1 (tag `v1.1-audit-p0`)

**Statut : préparée, NON exécutée.** Ne rien lancer de la section 3 sans l'accord explicite de Dany.
Aucune commande ci-dessous ne pousse quoi que ce soit avant la section 5.

## État de départ (relevé le 11 novembre 2026)

- `main` = `3de9d55` = `origin/main` (rien n'y a été fusionné pendant l'audit).
- `audit/etape-16-scenarios-paiement` contient **tout** le chapitre 1.1 : `main` est son ancêtre direct
  (base commune = `3de9d55`), 44 commits d'avance, 60 fichiers modifiés (+3440 / −695).
- Les 17 autres branches `audit/...` (étapes 1 à 15 et `audit/recette`) sont **toutes déjà contenues** dans
  `etape-16` (`git branch --no-merged audit/etape-16-scenarios-paiement` ne renvoie rien).
- Dépôt distant : `origin` (GitHub, `perline-cookies`). Seule la branche `origin/main` existe : aucune branche
  `audit/...` n'a été poussée.
- Scan des commits à fusionner : aucun motif de clé Stripe (`sk_`, `whsec_`, `rk_`). `.env.local` est ignoré.
- Fichiers déjà suivis dans `main` (hors périmètre de cette fusion) : `node_modules/` (500 fichiers) et
  `migrations_backup/` : nettoyage prévu au chapitre 7.2.

## 1. Vérifications avant de fusionner (sur `audit/etape-16-scenarios-paiement`)

```bash
git checkout audit/etape-16-scenarios-paiement
git status --short                                   # doit être vide
php bin/phpunit                                      # attendu : OK (66 tests)
php bin/console lint:container
php bin/console lint:twig templates
php bin/console doctrine:migrations:list             # tout « migrated » (base locale)
php bin/console doctrine:schema:update --dump-sql    # « Nothing to update »
git fetch origin                                     # main distant a-t-il bougé ?
git rev-parse main origin/main                       # doivent être identiques (3de9d55...)
```

Le seul avertissement connu de `doctrine:schema:validate` concerne le mapping de `Produit` (avis, favoris) :
préexistant, noté pour le chapitre 7.2.

Si `origin/main` a avancé : s'arrêter, on décide ensemble (fusionner `origin/main` dans `etape-16` d'abord).

## 2. Filet de sécurité

```bash
git tag sauvegarde-main-avant-v1.1 main              # repère local pour pouvoir revenir en arrière
```

## 3. Fusion et tag (à exécuter seulement sur ordre de Dany)

```bash
git checkout main
git merge --no-ff audit/etape-16-scenarios-paiement -m "Chapitre 1.1 : corrections P0 avant vente réelle (paiement, stock, webhook, migrations, contact)"
php bin/phpunit                                      # doit rester vert sur main
git tag -a v1.1-audit-p0 -m "Chapitre 1.1 du plan d'amélioration terminé et validé (11 novembre 2026)"
```

`--no-ff` crée un commit de fusion unique qui regroupe les 44 commits : l'historique de l'audit reste lisible
et on peut annuler le tout d'un seul `revert -m 1`.

## 4. Retour arrière (si un problème apparaît)

- **Avant tout `push`** : `git checkout main && git reset --hard sauvegarde-main-avant-v1.1` (et
  `git tag -d v1.1-audit-p0` si le tag a été créé). Rien n'est perdu : les branches `audit/...` existent encore.
- **Après un `push`** : ne pas réécrire l'historique distant. `git revert -m 1 <commit de fusion>` crée un
  commit d'annulation.

## 5. Dépôt distant (étape séparée, à confirmer à part)

```bash
git push origin main
git push origin v1.1-audit-p0
```

Si `origin/main` déclenche un déploiement automatique, vérifier d'abord le chapitre 7.3 (webhook Stripe de
production, `MAILER_DSN`, crons, worker Messenger, migrations : `migrations:list` puis sauvegarde avant `migrate`).

## 6. Que faire des branches `audit/...` (proposition)

1. **Ne pas les pousser** : leur contenu est dans `main` via la fusion ; les pousser créerait 17 branches
   redondantes sur GitHub.
2. Les garder **en local une semaine** après la fusion (filet de sécurité), puis les supprimer :
   ```bash
   git tag archive/audit-etape-16 audit/etape-16-scenarios-paiement   # un seul repère d'archive, facultatif
   git branch -d audit/etape-1 audit/etape-2 audit/etape-3 audit/etape-4-fuseau-horaire audit/etape-5-montant-admin audit/etape-6-mailpit-ports audit/recette audit/etape-7-success-id audit/etape-8-success-stripe audit/etape-9-idempotence audit/etape-10-montant-webhook audit/etape-11-compte-obligatoire audit/etape-12-adresse-retrait audit/etape-13-stock-boxes audit/etape-14-contact audit/etape-15-migrations audit/etape-16-scenarios-paiement
   ```
   `git branch -d` (et non `-D`) refuse de supprimer une branche non fusionnée : c'est le garde-fou.
3. Les chapitres suivants (1.2 et après) repartent d'une nouvelle branche créée **depuis `main`** après la
   fusion, par exemple `audit/etape-17-...`.
4. Le tag `v1.1-audit-p0` reste comme point de repère du chapitre 1.1.
