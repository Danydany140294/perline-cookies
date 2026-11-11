// Orchestrateur des tests de bout en bout : UNE commande -> `npm run e2e` (depuis le dossier e2e/).
//
// Ce qu'il fait, dans l'ordre :
//  1. vérifie les prérequis (docker, php, clé Stripe de TEST uniquement) ;
//  2. recrée de zéro une base MySQL JETABLE (conteneur creaself-e2e-mysql, port 3311) et un Mailpit
//     dédié (ports 1026 / 8026) : la base locale de développement n'est JAMAIS touchée ;
//  3. rejoue toutes les migrations sur cette base vierge, charge les fixtures, crée l'utilisateur de test ;
//  4. démarre l'application sur http://127.0.0.1:8001 (environnement « e2e », cache séparé) ;
//  5. lance Playwright, puis arrête tout (sauf avec --keep).
import { spawn, spawnSync, execFileSync } from 'node:child_process';
import { readFileSync, rmSync, mkdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE = path.resolve(ICI, '..');
const GARDER = process.argv.includes('--keep');
// --reuse : réutilise la base et le Mailpit d'un lancement précédent fait avec --keep (mise au point rapide)
const REUTILISER = process.argv.includes('--reuse');
const ARGS_PLAYWRIGHT = process.argv.slice(2).filter((a) => a !== '--keep' && a !== '--reuse');

export const CONF = {
  mysql: { conteneur: 'creaself-e2e-mysql', port: 3311, base: 'creaself_e2e', motDePasse: 'e2e' },
  mailpit: { conteneur: 'creaself-e2e-mailpit', smtp: 1026, api: 8026 },
  appPort: 8001,
  appPort2: 8004, // second serveur sur LA MÊME base : sert à tester de vraies requêtes simultanées (verrous MySQL)
  secretWebhook: 'whsec_e2e_secret_local_uniquement',
  utilisateur: { email: 'e2e@perline.test', motDePasse: 'E2e-Perline-2026!' },
};

const log = (m) => console.log(`\n▶ ${m}`);
const echec = (m) => { console.error(`\n✖ ${m}`); process.exit(1); };
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

function lire(cmd, args, options = {}) {
  const r = spawnSync(cmd, args, { cwd: RACINE, encoding: 'utf8', shell: false, ...options });
  return { code: r.status, sortie: `${r.stdout ?? ''}${r.stderr ?? ''}` };
}

// ── 1. Prérequis ────────────────────────────────────────────────────────────────────────────
log('Vérification des prérequis');
if (lire('docker', ['info']).code !== 0) echec('Docker ne répond pas : démarrez Docker Desktop.');
if (lire('php', ['-v']).code !== 0) echec('PHP introuvable dans le PATH.');

// Garde-fou : on ne lance JAMAIS les tests avec une clé Stripe de production
const envFichier = existsSync(path.join(RACINE, '.env')) ? readFileSync(path.join(RACINE, '.env'), 'utf8') : '';
const cleStripe = (envFichier.match(/^STRIPE_SECRET_KEY=["']?([^"'\r\n]+)/m) ?? [])[1] ?? process.env.STRIPE_SECRET_KEY ?? '';
if (!cleStripe.startsWith('sk_test_')) {
  echec('STRIPE_SECRET_KEY doit être une clé de TEST (sk_test_...). Tests de bout en bout refusés.');
}

const DATABASE_URL = `mysql://root:${CONF.mysql.motDePasse}@127.0.0.1:${CONF.mysql.port}/${CONF.mysql.base}?serverVersion=8.0.32&charset=utf8mb4`;
const envApp = {
  ...process.env,
  APP_ENV: 'e2e',
  APP_DEBUG: '0',
  DATABASE_URL,
  MAILER_DSN: `smtp://127.0.0.1:${CONF.mailpit.smtp}`,
  STRIPE_WEBHOOK_SECRET: CONF.secretWebhook,
};
const envCli = { ...envApp, APP_ENV: 'dev', APP_DEBUG: '1' }; // migrations et fixtures : bundles de dev

// ── 2. Conteneurs jetables ──────────────────────────────────────────────────────────────────
log('Création de la base MySQL jetable et du Mailpit de test');
const dejaLa = REUTILISER && lire('docker', ['inspect', '-f', '{{.State.Running}}', CONF.mysql.conteneur]).sortie.trim() === 'true';
if (!dejaLa) lire('docker', ['rm', '-f', CONF.mysql.conteneur, CONF.mailpit.conteneur]);
if (!dejaLa && lire('docker', ['run', '-d', '--name', CONF.mysql.conteneur, '-e', `MYSQL_ROOT_PASSWORD=${CONF.mysql.motDePasse}`, '-e', `MYSQL_DATABASE=${CONF.mysql.base}`, '-p', `${CONF.mysql.port}:3306`, 'mysql:8.0']).code !== 0) echec('Impossible de démarrer MySQL (port 3311 déjà pris ?).');
if (!dejaLa && lire('docker', ['run', '-d', '--name', CONF.mailpit.conteneur, '-p', `${CONF.mailpit.smtp}:1025`, '-p', `${CONF.mailpit.api}:8025`, 'axllent/mailpit']).code !== 0) echec('Impossible de démarrer Mailpit (ports 1026 / 8026 pris ?).');

let serveur = null;
let serveur2 = null;
async function arreter() {
  for (const s of [serveur, serveur2]) {
    if (s) { s.kill(); if (process.platform === 'win32') lire('taskkill', ['/PID', String(s.pid), '/T', '/F']); }
  }
  if (!GARDER) lire('docker', ['rm', '-f', CONF.mysql.conteneur, CONF.mailpit.conteneur]);
  else console.log(`\n(--keep) base et Mailpit conservés : mysql ${CONF.mysql.port}, mailpit http://127.0.0.1:${CONF.mailpit.api}`);
}
process.on('SIGINT', async () => { await arreter(); process.exit(130); });

try {
  // MySQL redémarre une fois pendant son initialisation : on attend deux réponses consécutives
  let ok = 0;
  for (let i = 0; i < 90 && ok < 2; i++) {
    const r = lire('docker', ['exec', CONF.mysql.conteneur, 'mysql', '-uroot', `-p${CONF.mysql.motDePasse}`, '-e', 'SELECT 1']);
    ok = r.code === 0 ? ok + 1 : 0;
    await dormir(2000);
  }
  if (ok < 2) throw new Error('MySQL ne répond pas.');

  // Dernier garde-fou : on vérifie que l'application visera bien la base jetable
  const cible = lire('php', ['bin/console', 'dbal:run-sql', '--force-fetch', 'SELECT DATABASE() AS b, @@hostname AS h'], { env: envCli });
  if (!cible.sortie.includes(CONF.mysql.base)) throw new Error('La base visée n\'est pas la base jetable : arrêt par sécurité.\n' + cible.sortie);

  // ── 3. Schéma, fixtures, utilisateur de test ──────────────────────────────────────────────
  let r;
  if (!dejaLa) {
  log('Migrations rejouées sur la base vierge');
  r = lire('php', ['bin/console', 'doctrine:migrations:migrate', '--no-interaction'], { env: envCli });
  if (r.code !== 0) throw new Error('Migrations en échec :\n' + r.sortie);
  console.log(r.sortie.split('\n').filter((l) => /migrations executed|Successfully/.test(l)).join('\n'));

  log('Chargement des fixtures');
  r = lire('php', ['bin/console', 'doctrine:fixtures:load', '--no-interaction'], { env: envCli });
  if (r.code !== 0) throw new Error('Fixtures en échec :\n' + r.sortie);

  log('Création de l\'utilisateur de test');
  r = lire('php', ['bin/console', 'security:hash-password', CONF.utilisateur.motDePasse, 'App\\Entity\\User', '--no-interaction'], { env: envCli });
  const hash = (r.sortie.match(/Password hash\s+(\$[^\s]+)/) ?? [])[1];
  if (!hash) throw new Error('Hash du mot de passe introuvable :\n' + r.sortie);
  const sql = `INSERT INTO user (email, roles, password, nom, prenom, points_fidelite, date_inscription) VALUES ('${CONF.utilisateur.email}', '["ROLE_USER"]', '${hash}', 'E2E', 'Testeur', 0, NOW());`;
  r = lire('docker', ['exec', CONF.mysql.conteneur, 'mysql', '-uroot', `-p${CONF.mysql.motDePasse}`, CONF.mysql.base, '-e', sql]);
  if (r.code !== 0 && !/Using a password/.test(r.sortie)) throw new Error('Création de l\'utilisateur en échec :\n' + r.sortie);
  }

  // ── 4. Application ────────────────────────────────────────────────────────────────────────
  log(`Démarrage de l'application sur http://127.0.0.1:${CONF.appPort} (environnement e2e)`);
  rmSync(path.join(RACINE, 'var', 'cache', 'e2e'), { recursive: true, force: true }); // limiteurs de débit remis à zéro
  mkdirSync(path.join(RACINE, 'var', 'cache'), { recursive: true });
  r = lire('php', ['bin/console', 'cache:warmup'], { env: envApp });
  if (r.code !== 0) throw new Error('Préchauffage du cache e2e en échec :\n' + r.sortie);
  // variables_order=EGPCS : le serveur intégré de PHP n'expose pas l'environnement dans $_ENV par défaut, et Dotenv
  // laisserait alors le fichier .env (base de DÉVELOPPEMENT) écraser DATABASE_URL.
  serveur = spawn('php', ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${CONF.appPort}`, '-t', 'public'], { cwd: RACINE, env: envApp, stdio: 'ignore' });
  // Le second serveur propose AUSSI de tester le réglage « retrait désactivé » (LIVRAISON_RETRAIT_ACTIF=0) : mêmes fichiers,
  // même base, même session (cookies partagés sur 127.0.0.1), seule la configuration diffère.
  serveur2 = spawn('php', ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${CONF.appPort2}`, '-t', 'public'], { cwd: RACINE, env: { ...envApp, LIVRAISON_RETRAIT_ACTIF: '0' }, stdio: 'ignore' });
  for (const port of [CONF.appPort, CONF.appPort2]) {
    let pret = false;
    for (let i = 0; i < 40 && !pret; i++) {
      await dormir(500);
      try { pret = (await fetch(`http://127.0.0.1:${port}/`)).status < 500; } catch { /* pas encore prêt */ }
    }
    if (!pret) throw new Error(`L'application ne répond pas sur le port ${port}.`);
  }

  // ── 5. Playwright ─────────────────────────────────────────────────────────────────────────
  log('Tests Playwright');
  const pw = spawnSync(process.platform === 'win32' ? 'npx.cmd' : 'npx', ['playwright', 'test', ...ARGS_PLAYWRIGHT], {
    cwd: ICI,
    stdio: 'inherit',
    shell: process.platform === 'win32',
    env: {
      ...process.env,
      E2E_BASE_URL: `http://127.0.0.1:${CONF.appPort}`,
      E2E_BASE_URL2: `http://127.0.0.1:${CONF.appPort2}`,
      E2E_WHSEC: CONF.secretWebhook,
      E2E_MYSQL_CONTENEUR: CONF.mysql.conteneur,
      E2E_MYSQL_BASE: CONF.mysql.base,
      E2E_MYSQL_MDP: CONF.mysql.motDePasse,
      E2E_MAILPIT_API: `http://127.0.0.1:${CONF.mailpit.api}`,
      E2E_USER_EMAIL: CONF.utilisateur.email,
      E2E_USER_MDP: CONF.utilisateur.motDePasse,
      E2E_DATABASE_URL: DATABASE_URL,
      E2E_RACINE: RACINE,
    },
  });
  await arreter();
  process.exit(pw.status ?? 1);
} catch (e) {
  console.error(`\n✖ ${e.message}`);
  await arreter();
  process.exit(1);
}
