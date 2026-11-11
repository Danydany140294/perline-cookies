// Aides communes aux scénarios.
import { expect } from '@playwright/test';
import { lignes, valeur } from './db.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

export async function ajouterCookie(request, id, quantite = 1) {
  const r = await request.post(`/panier/ajouter-produit/${id}`, { form: { quantite: String(quantite) }, headers: XHR });
  return { statut: r.status(), json: await r.json() };
}

export async function ajouterBox(request, id, quantite = 1) {
  const r = await request.post(`/panier/ajouter-box/${id}`, { form: { quantite: String(quantite) }, headers: XHR });
  return { statut: r.status(), json: await r.json() };
}

/** @param cookies ex. { 1: 6, 2: 6 } (id du cookie -> quantité) */
export async function ajouterBoxPerso(request, cookies, taille) {
  const donnees = { cookies: Object.fromEntries(Object.entries(cookies).map(([id, q]) => [`produit_${id}`, q])), taille };
  const r = await request.post('/panier/ajouter-box-personnalisable', { data: donnees });
  return { statut: r.status(), json: await r.json() };
}

export function quantiteEnPanier(produitId) {
  return Number(valeur(`SELECT COALESCE(SUM(quantite),0) FROM ligne_panier WHERE produit_id = ${produitId}`));
}

export function stock(produitId) {
  return Number(valeur(`SELECT stock FROM produit WHERE id = ${produitId}`));
}

export function nombreDeCommandes(statut = null) {
  return Number(valeur(`SELECT COUNT(*) FROM commande ${statut ? `WHERE statut = '${statut}'` : ''}`));
}

/** La commande la plus récente : { id, statut, total, session, numero, mode } (frais : voir fraisDe) */
export function derniereCommande() {
  const l = lignes('SELECT id, statut, total_ttc, stripe_session_id, numero_commande, mode_livraison FROM commande ORDER BY id DESC LIMIT 1');
  if (!l.length) return null;
  const [id, statut, total, session, numero, mode] = l[0];
  return { id: Number(id), statut, total: Number(total), session: session === 'NULL' ? null : session, numero, mode };
}

export function commande(id) {
  const l = lignes(`SELECT id, statut, total_ttc, stripe_session_id, numero_commande, mode_livraison FROM commande WHERE id = ${id}`);
  if (!l.length) return null;
  const [i, statut, total, session, numero, mode] = l[0];
  return { id: Number(i), statut, total: Number(total), session: session === 'NULL' ? null : session, numero, mode };
}

/** Écoute les fenêtres natives (alert, confirm...) : un scénario doit prouver qu'il n'y en a aucune. */
export function surveillerFenetresNatives(page) {
  const messages = [];
  page.on('dialog', async (d) => { messages.push(d.message()); await d.dismiss(); });
  return messages;
}

export function bandeau(page) {
  return page.locator('[role="alert"]');
}

/**
 * Clic sur « Payer » en retrait (ou livraison) : le site crée la commande « En attente » et la session Stripe
 * (appel réel à l'API Stripe en mode test), puis le navigateur est redirigé vers Stripe : on COUPE cette
 * redirection (aucune saisie sur la page Stripe). Renvoie la commande créée, ou null si le paiement est refusé.
 */
export async function cliquerSurPayer(page, mode = 'retrait') {
  // La page hébergée par Stripe est REMPLACÉE par une page vide : aucune saisie ne se fait chez Stripe
  await page.route(/checkout\.stripe\.com/, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>Page Stripe simulée</body></html>' }));
  await page.goto('/paiement');
  await page.check(`input[name="mode"][value="${mode}"]`);
  await page.waitForLoadState('networkidle'); // l'adresse par défaut est enregistrée en session par un appel asynchrone
  const avant = nombreDeCommandes();
  const alertesAvant = await bandeau(page).count(); // un message déjà affiché (flash d'une action précédente) n'est pas un refus
  await page.click('#checkout-btn');

  // Attend soit la commande avec sa session Stripe, soit un bandeau de refus
  for (let i = 0; i < 60; i++) {
    const c = derniereCommande();
    if (c && c.session && nombreDeCommandes() > avant) {
      // Stripe.js redirige ensuite le navigateur : on attend que cette redirection (vers la page simulée) soit terminée,
      // sinon elle viendrait interrompre la navigation suivante du scénario.
      await page.waitForURL(/checkout\.stripe\.com/, { timeout: 20_000 }).catch(() => {});
      return c;
    }
    if ((await bandeau(page).count()) > alertesAvant) return null;
    await page.waitForTimeout(500);
  }
  throw new Error('Ni commande ni message de refus après le clic sur Payer.');
}

export async function emails() {
  const r = await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/messages`);
  return (await r.json()).messages ?? [];
}

export async function viderEmails() {
  await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/messages`, { method: 'DELETE' });
}

export { expect };

/** Valeur d'une colonne de la commande (texte ; 'NULL' devient null). */
export function colonneCommande(id, colonne) {
  const v = valeur(`SELECT ${colonne} FROM commande WHERE id = ${id}`);
  return v === 'NULL' ? null : v;
}
