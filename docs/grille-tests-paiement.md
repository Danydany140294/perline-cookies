# Grille de tests : scénarios de paiement (chapitre 1.1, dernière ligne)

Prérequis : Docker démarré, `symfony serve`, Mailpit (http://127.0.0.1:8025), et dans un terminal :

```bash
./stripe.exe listen --forward-to http://127.0.0.1:8000/webhook/stripe --events checkout.session.completed,checkout.session.expired
```

(`STRIPE_WEBHOOK_SECRET` de `.env.local` = le `whsec_` affiché par cette commande.)

Pour regarder les commandes après chaque test :

```bash
php bin/console dbal:run-sql --force-fetch "SELECT id, numero_commande, statut, total_ttc, stripe_session_id FROM commande ORDER BY id DESC LIMIT 5"
```

Notez avant chaque test : le stock d'un cookie et le contenu du panier.

| # | Scénario | Comment | Résultat attendu |
|---|---|---|---|
| S1 | **Carte refusée** | Payer avec `4000 0000 0000 0002` | Stripe affiche « carte refusée » sur sa page, rien dans `stripe listen`. Commande `en_attente`, stock inchangé, panier intact. Réessayer sur la même page avec `4242 4242 4242 4242` : commande `payee`, stock −1 une seule fois. |
| S2 | **Annulation par le client** | Sur la page Stripe, cliquer sur « Retour » | Retour au panier, articles toujours là, commande `en_attente` (la session Stripe reste ouverte jusqu'à son expiration), stock inchangé. |
| S3 | **Session expirée** | Cliquer sur « Payer », relever `stripe_session_id` de la commande `en_attente` (SQL ci-dessus), puis `./stripe.exe checkout sessions expire cs_test_...` | `stripe listen` affiche `checkout.session.expired` `[200]`. La commande passe `annulee` (elle n'est **pas** supprimée), stock et panier inchangés. L'admin l'affiche « Annulée ». Rouvrir la page Stripe : elle indique que la session a expiré. |
| S4 | **Expiration rejouée / commande déjà payée** | Relancer l'expiration sur une session déjà expirée, ou sur une commande payée | Réponse `200`, statut inchangé (jamais de retour de `payee` à `annulee`). |
| S5 | **Webhook en retard** | Arrêter `stripe listen`, payer avec `4242…`, observer, puis relancer `stripe listen` | Page d'accueil : message « Votre paiement est en cours de confirmation ». Commande `en_attente`, stock inchangé, panier conservé (en base). Une fois le webhook reçu : `payee`, stock −1 une fois, e-mail de confirmation. **L'étape « rejouer le webhook » demande un choix, voir ci-dessous.** |
| S6 | **Nettoyage** | `php bin/console app:nettoyer-commandes-en-attente --dry-run` | Ne liste jamais une commande `annulee`, `a_verifier` ni `payee`. Seules les `en_attente` de plus de 48 h apparaissent. |

## Déjà couvert par des tests automatiques (`php bin/phpunit`)

- Session expirée : `en_attente` → `annulee` sans suppression ; jamais sur `payee` / `a_verifier` ; session différente ignorée ; rejouée deux fois = reste `annulee`.
- Paiement reçu sur une commande déjà `annulee` : jamais ignoré, la commande passe `a_verifier` avec un log critique.
- Nettoyage : une commande `annulee`, `a_verifier` ou `payee` n'est jamais supprimée.
- Paiement réussi, idempotence, écart de montant, stock insuffisant : voir `tests/Service/PaiementCommandeServiceTest.php`.
