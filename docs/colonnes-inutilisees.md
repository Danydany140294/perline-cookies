# Colonnes inutilisées : `box.stock` et `produit.disponible`

**État : migration PRÉPARÉE, NON exécutée, NON fusionnée.** Elle supprime des données (le contenu de deux colonnes), donc elle attend la décision du propriétaire.

## Pourquoi ces deux colonnes ne servent plus

- `produit.disponible` : remplacée au chapitre 1.6 par une disponibilité **calculée** (`actif ET stock > 0`, voir `Produit::isDisponible()`). L'application ne l'écrit plus et ne la lit plus.
- `box.stock` : le stock d'une box est **calculé** d'après le stock des cookies de sa recette (`Box::getStockDisponible()`). Plus aucun code ne lit ni n'écrit cette colonne (les méthodes de `CommandeService` qui l'utilisaient ont été supprimées au chapitre 7.2).

## Ce qui est prêt

La branche **`prepare/suppression-colonnes-inutilisees`** (à partir de `main`, jamais fusionnée) contient tout :

1. la migration `migrations/Version20261117230000.php` (`ALTER TABLE box DROP COLUMN stock`, `ALTER TABLE produit DROP COLUMN disponible`, avec un `down()` qui les recrée avec leurs valeurs par défaut : les valeurs d'origine ne sont PAS restaurées) ;
2. la suppression des champs correspondants dans `Produit` et `Box`, des appels dans les fixtures et dans les tests, et de la colonne dans la réinitialisation des tests de bout en bout.

Elle a été vérifiée uniquement sur la base **jetable** des tests de bout en bout (les migrations y sont rejouées depuis zéro). La base de développement n'a pas été touchée.

## Procédure d'exécution (quand le propriétaire dit oui)

1. Sauvegarder : `mysqldump --single-transaction <base> > sauvegarde-avant-colonnes.sql` (sauvegarde de production avant, aussi).
2. `git merge --no-ff prepare/suppression-colonnes-inutilisees` dans `main` après validation.
3. `php bin/console doctrine:migrations:list` puis `doctrine:migrations:migrate`.
4. `php bin/console doctrine:schema:validate` ; relancer les tests unitaires et de bout en bout.
5. Retour arrière possible avec `doctrine:migrations:execute --down` (colonnes recréées vides) ou en restaurant la sauvegarde.
