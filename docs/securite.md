# Sécurité (chapitre 7.1) : état, réglages et points serveur

Mise à jour : 17 novembre 2026.

## 1. Dépendances

- **Avant** : `composer audit` signalait **57 avis de sécurité sur 22 paquets** (surtout Symfony 6.4.x, EasyAdmin, Twig, phpunit).
- **Après** `composer update` dans les versions compatibles (Symfony reste en **6.4 LTS** grâce à `extra.symfony.require: ^6.4`, EasyAdmin 4.x, Doctrine ORM 3.x, Twig 3.x) : **0 avis** (`composer audit` : « No security vulnerability advisories found »).
- Tests après la mise à jour : suite unitaire et suite de bout en bout complètes vertes (voir le bilan).
- Une migration d'index a été nécessaire : `Version20261117104645` (table `messenger_messages`, index seulement, aucune donnée).
- **Versions majeures NON appliquées** (décision à prendre, voir les questions) : Symfony 7.x / 8.x, EasyAdmin 5, DoctrineBundle 3, DBAL 4, PHPUnit 10+, Stripe PHP 20+. Elles ne sont pas nécessaires pour la sécurité aujourd'hui (aucun avis restant) mais Symfony 6.4 sort du support de sécurité fin 2027 : prévoir la migration avant.
- À refaire **avant chaque mise en production** : `composer audit` (et idéalement chaque mois).

## 2. Secrets

- Aucun secret dans le dépôt Git : `.env` et `.env.*` sont ignorés (seul `.env.example`, sans secret, est suivi). Scan de tout l'historique (toutes les branches) et de l'arbre actuel : aucune clé `sk_`/`rk_`/`pk_live_`/`whsec_` trouvée (le binaire `stripe.exe` contient seulement le texte « sk_live » dans son code : ce n'est pas une clé).
- Secrets uniquement dans les variables d'environnement (`APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`, `STRIPE_*`). L'écran « Paramètres de la boutique » ne permet jamais de saisir un secret (liste blanche de champs, testée).
- **À faire à la main** : si une clé Stripe a déjà été affichée dans un terminal ou un message (cela est arrivé le 16 novembre 2026), la **régénérer** dans le tableau de bord Stripe ; utiliser `stripe login` plutôt que `--api-key`.

## 3. En-têtes de sécurité (appliqués par l'application)

Ajoutés à toutes les réponses par `App\EventListener\EnTetesSecuriteListener` (sans écraser un en-tête déjà défini) :

| En-tête | Valeur | Rôle |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | interdit au navigateur de deviner le type d'un fichier |
| `X-Frame-Options` | `SAMEORIGIN` | pas d'affichage du site dans le cadre d'un autre site (clickjacking) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | limite les informations envoyées aux sites tiers |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` | désactive les fonctions du navigateur inutiles |
| `Cross-Origin-Opener-Policy` | `same-origin` | isole la fenêtre du site |
| `Strict-Transport-Security` | `max-age=15552000` (180 jours) | **uniquement** en production ET en HTTPS ; sans `includeSubDomains` ni `preload` |

**Pas de Content-Security-Policy pour l'instant** : le site charge encore Tailwind depuis un CDN et utilise des scripts en ligne ; une CSP stricte le casserait. Elle viendra avec le chapitre front (installation propre de Tailwind, scripts avec nonce), voir les questions.

## 4. Sessions et cookies

- Cookie de session : `HttpOnly` (inaccessible au JavaScript), `SameSite=Lax`, `Secure` **forcé en production** (`when@prod: cookie_secure: true`), `auto` en développement (HTTP local).
- Cookie « se souvenir de moi » : `secure: auto`, `httponly`, `samesite=lax`, 7 jours.
- Fixation de session : `session_fixation_strategy: migrate`.
- Connexion : limitation à 5 tentatives par 15 minutes (`LOGIN_LIMITE` ne doit jamais être défini en production), jeton CSRF sur le formulaire, e-mail à confirmer.
- **Déconnexion protégée par un jeton CSRF** (`logout: enable_csrf`) : l'adresse `/logout` sans jeton répond 403 ; les liens du site utilisent `logout_path()`.
- Tous les formulaires sensibles (profil, adresses, mot de passe, paramètres, exports) portent un jeton CSRF.

## 5. Configuration de production (rappels)

- `APP_ENV=prod` et `APP_DEBUG=0` (jamais de page d'erreur détaillée) ; le profileur n'existe qu'en développement.
- Les journaux de production partent sur la sortie d'erreur au format JSON (niveau « error » et au-delà, 404/405 exclus).
- Pages d'erreur personnalisées : 403, 404 et 500 (`templates/bundles/TwigBundle/Exception/`), sans détail technique.
- `/admin` : accès réservé à `ROLE_GESTIONNAIRE` ; rubriques sensibles (utilisateurs, journal, paniers, exports, paramètres) à `ROLE_ADMIN` ; `ROLE_ADMIN` ne s'attribue que par `php bin/console app:promote-admin`.

## 6. Ce qui relève du SERVEUR (à faire à la mise en ligne)

- **HTTPS** obligatoire (certificat valide, redirection HTTP vers HTTPS), puis **HSTS** (l'application l'envoie déjà en prod+HTTPS ; vérifier qu'il n'est pas supprimé par le serveur ou un proxy).
- Derrière un **reverse proxy** ou un répartiteur de charge : déclarer les proxys de confiance (`framework.trusted_proxies`, `trusted_headers`) sinon l'application ne voit pas qu'elle est en HTTPS et `isSecure()` est faux.
- Racine web = `public/` seulement (`var/`, `config/`, `src/`, `.env*`, `vendor/` jamais servis) ; masquer la version de PHP (`expose_php=Off`) et du serveur web.
- PHP : OPcache activé, `display_errors=Off`, `session.cookie_*` laissés à l'application, droits d'écriture limités à `var/` et au dossier des images.
- MySQL : utilisateur dédié aux droits limités, accessible seulement depuis l'application.
- Pare-feu, mises à jour du système, sauvegardes (voir `docs/MISE-EN-LIGNE.md`), surveillance des journaux.
- Fichiers envoyés (images de l'administration) : le dossier `public/asset/images/produits/` ne doit **jamais** exécuter de PHP (désactiver l'interprétation de scripts dans ce dossier côté serveur web).
- Limitation de débit globale (anti-abus) et protection contre les attaques par déni de service : service de l'hébergeur ou pare-feu applicatif.

## 7. Idées non faites (voir les questions)

- Content-Security-Policy (après le chapitre front).
- Double authentification de l'administration (2FA).
- `composer audit` automatique à chaque livraison (intégration continue).
