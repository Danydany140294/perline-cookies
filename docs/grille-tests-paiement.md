# Grille de tests : scénarios de paiement (chapitre 1.1, dernière ligne)

Dans les commandes ci-dessous, remplacez `sk_test_XXX` par votre clé secrète **de test** (ne jamais la
coller dans un fichier du dépôt ni dans un message). `STRIPE_WEBHOOK_SECRET` de `.env.local` doit être le
`whsec_` affiché par `stripe listen`.

## Prérequis

Docker démarré, `symfony serve`, Mailpit (http://127.0.0.1:8025), et dans un terminal :

```bash
./stripe.exe listen --api-key sk_test_XXX --forward-to http://127.0.0.1:8000/webhook/stripe --events checkout.session.completed,checkout.session.expired
```

Pour regarder les commandes après chaque test :

```bash
php bin/console dbal:run-sql --force-fetch "SELECT id, numero_commande, statut, total_ttc, stripe_session_id FROM commande ORDER BY id DESC LIMIT 5"
```

Notez avant chaque test : le stock d'un cookie et le contenu du panier.

## Les scénarios

### S1. Carte refusée
Payer avec `4000 0000 0000 0002`.
- Stripe affiche « carte refusée » sur sa page ; rien dans `stripe listen`.
- Commande `en_attente`, stock inchangé, panier intact.
- Réessayer sur la même page avec `4242 4242 4242 4242` : commande `payee`, stock −1 **une seule fois**.

### S2. Annulation par le client
Sur la page Stripe, cliquer sur « Retour ».
- Retour au panier, articles toujours là.
- Commande `en_attente` (la session Stripe reste ouverte jusqu'à son expiration), stock inchangé.

### S3. Session expirée
1. Cliquer sur « Payer » (ne pas payer). Relever `stripe_session_id` de la commande `en_attente` (SQL ci-dessus).
2. Faire expirer la session :
   ```bash
   ./stripe.exe checkout sessions expire cs_test_XXX --api-key sk_test_XXX
   ```
- `stripe listen` affiche `checkout.session.expired` `[200]`.
- La commande passe `annulee` (elle n'est **pas** supprimée) ; stock et panier inchangés ; l'admin l'affiche « Annulée ».
- Rouvrir la page Stripe : elle indique que la session a expiré.

### S4. Expiration rejouée
Retrouver l'événement et le rejouer deux fois :
```bash
./stripe.exe events list --type checkout.session.expired --limit 3 --api-key sk_test_XXX
php bin/console app:stripe-rejouer-evenement evt_XXX
php bin/console app:stripe-rejouer-evenement evt_XXX
```
- Les deux fois : « Déjà traité : rien n'a changé (idempotence) ». La commande reste `annulee`.

### S5. Webhook en retard, puis rattrapage
1. **Arrêter** `stripe listen` (Ctrl+C), puis payer avec `4242 4242 4242 4242`.
2. Page d'accueil : message « Votre paiement est en cours de confirmation ». Commande `en_attente`, stock inchangé, panier conservé.
3. Retrouver l'événement du paiement :
   ```bash
   ./stripe.exe events list --type checkout.session.completed --limit 3 --api-key sk_test_XXX
   ```
4. Vérifier sans rien changer, puis rejouer :
   ```bash
   php bin/console app:stripe-rejouer-evenement evt_XXX --dry-run
   php bin/console app:stripe-rejouer-evenement evt_XXX
   ```
   → « Commande payée : stock retiré, panier vidé, e-mail de confirmation envoyé ». Commande `payee`, stock −1, e-mail dans Mailpit.
5. **Rejouer une seconde fois** la même commande : « Déjà traité ». Stock inchangé, **pas** de second e-mail.

### S6. Nettoyage
```bash
php bin/console app:nettoyer-commandes-en-attente --dry-run
```
Ne liste jamais une commande `annulee`, `a_verifier` ni `payee`. Seules les `en_attente` de plus de 48 h apparaissent.

## Déjà couvert par des tests automatiques (`php bin/phpunit`)

- Session expirée : `en_attente` → `annulee` sans suppression ; jamais sur `payee` / `a_verifier` ; session différente ignorée ; rejouée deux fois = reste `annulee`.
- Paiement reçu sur une commande déjà `annulee` : jamais ignoré, la commande passe `a_verifier` avec un log critique.
- Rejeu d'un événement (même code que le webhook) : un paiement n'est traité qu'une fois (stock, e-mail), un écart reste `a_verifier` avec un seul log critique, un paiement sans référence ne crée aucune commande.
- Webhook : signature invalide ou absente refusée (400), événement signé traité (200).
- Commande de rattrapage : identifiant invalide, événement introuvable, type non géré, `--dry-run`, déjà traité.
- Nettoyage : une commande `annulee`, `a_verifier` ou `payee` n'est jamais supprimée.
- Paiement réussi, idempotence, écart de montant, stock insuffisant : voir `tests/Service/PaiementCommandeServiceTest.php`.

## Résultats : grille jouée le 11 novembre 2026 (branche `audit/etape-16-scenarios-paiement`)

Tous les scénarios sont **validés**.

| # | Scénario | Résultat observé |
|---|---|---|
| S1 | Carte refusée | Erreur Stripe avec `4000 0000 0000 0002`, puis paiement accepté avec `4242 4242 4242 4242` sur la même page. Commande 96 `payee`, stock −1 (une seule fois). |
| S2 | Annulation par le client | Retour au panier, contenu intact. Commande 97 `en_attente`. |
| S3 | Session expirée | `checkout sessions expire` → `checkout.session.expired [200]`. Commande 97 `annulee`, non supprimée. |
| S4 | Expiration rejouée | `app:stripe-rejouer-evenement` lancé deux fois → « Déjà traité » les deux fois. La commande 97 reste `annulee`. |
| S5 | Webhook en retard | `stripe listen` coupé : bandeau « paiement en cours de confirmation », commande 98 `en_attente`, stock inchangé. Rattrapage : `payee`, Oreo 39 → 38, un seul e-mail. 2e rejeu : « Déjà traité », pas de second e-mail. |
| S6 | Nettoyage | `--dry-run` : aucune commande à supprimer. |

Conclusion : la dernière ligne du chapitre 1.1 du PDF (« Tester les paiements refusés, annulés, abandonnés,
réussis et les webhooks reçus en retard ») est validée. Le chapitre 1.1 est terminé.
