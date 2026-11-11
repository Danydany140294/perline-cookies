# Tests de bout en bout (Playwright)

Scénarios automatiques des chapitres 1.1 et 1.2 : contrôles du panier, prix de la box personnalisable,
paiement et webhook Stripe (grille S1 à S6), numéro de commande, statuts, historique, remboursements, administration
(35 scénarios, environ 3 minutes).

## Lancer les tests : une seule commande

```bash
cd e2e
npm install          # une seule fois (installe Playwright ; aucun navigateur n'est téléchargé)
npm run e2e          # prépare tout, joue les scénarios, nettoie
```

Options utiles :

```bash
npm run e2e:keep                       # garde la base et l'application après les tests (pour enquêter)
npm run e2e -- tests/03-paiement-scenarios.spec.js   # un seul fichier
npm run e2e -- -g "S3 session expirée"                # un seul scénario
npx playwright show-report             # rapport HTML du dernier lancement
```

## Prérequis

- **Docker** démarré (la base de test est un conteneur MySQL jetable),
- **PHP** et **Node** dans le PATH,
- **Google Chrome** installé (Playwright le pilote : aucun téléchargement de navigateur),
- une clé Stripe de **test** (`sk_test_…`) dans `.env` : les tests créent de vraies sessions Stripe en mode test.
  Le lancement est **refusé** si la clé n'est pas une clé de test.

## Ce que fait `npm run e2e`

1. Recrée de zéro une base MySQL **jetable** (`creaself-e2e-mysql`, port 3311) et un Mailpit dédié (ports 1026 / 8026).
   **La base locale de développement n'est jamais touchée** (un garde-fou vérifie le nom de la base avant tout).
2. Rejoue toutes les migrations sur cette base vierge (ce qui prouve au passage que le schéma se reconstruit),
   charge les fixtures, crée l'utilisateur de test `e2e@perline.test`.
3. Démarre l'application sur `http://127.0.0.1:8001` (environnement `e2e`, cache séparé de `var/cache/dev`).
4. Joue les scénarios, puis supprime conteneurs et serveur (sauf avec `--keep`).

Chaque scénario repart d'un état connu (`lib/etat.js` : stocks à 50, panier et commandes vidés, prix de la box
personnalisable 12,99 / 19,99 / 29,99).

## Ce qui est automatisé, et ce qui reste manuel

| Scénario | Automatisé | Comment |
|---|---|---|
| Panier : plafond de stock, cumul cookies + boxes, « + » (page et modale), box perso forgée, box désactivée | oui | vrais clics et vraies requêtes |
| Prix de la box perso (6, 12, 24) partout, modification dans l'admin, prix indéfini | oui | page `/box`, panier, commande |
| Paiement normal, deux onglets, composition figée | oui | clic sur « Payer » réel, webhook signé |
| S2 annulation, S3 session expirée, S4 rejeu, S5 webhook en retard, écart de montant, paiement sur commande annulée, S6 nettoyage | oui | idem |
| Numéro de commande PER-AAAAMMJJ-XXXXXX, unicité en base | oui | clic sur « Payer », insertion d'un doublon refusée |
| Historique des statuts (client, webhook), remboursement partiel / total / rejeu, paiement inconnu | oui | webhook charge.refunded signé |
| Administration : transition de statut autorisée / refusée, remise en stock à cocher (une fois) | oui | vraie page d'édition EasyAdmin (admin des fixtures) |
| **S1 carte refusée** (`4000 0000 0000 0002`) | **non, à la main** | se joue sur la page hébergée par Stripe : aucun numéro de carte n'est saisi par les tests |
| Rattrapage `app:stripe-rejouer-evenement` | tests unitaires seulement | l'événement doit exister chez Stripe |

## Fichiers

- `run.mjs` : orchestrateur (conteneurs, migrations, fixtures, serveur, Playwright).
- `lib/` : accès base (`db.js`), état de départ (`etat.js`), faux événements Stripe signés (`stripe.js`), aides (`aide.js`).
- `tests/` : les scénarios.
