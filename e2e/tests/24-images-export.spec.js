// Chapitre 1.6, bloc 4 : envoi d'images depuis l'administration (types, taille, nom aléatoire) et exports CSV.
import { test, request } from '@playwright/test';
import { readdirSync, existsSync, rmSync } from 'node:fs';
import path from 'node:path';
import { reinitialiser, creerAutreClient, COOKIE } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(() => reinitialiser());

const RACINE = path.resolve(process.cwd(), '..');
const DOSSIER = path.join(RACINE, 'public', 'asset', 'images', 'produits');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const GIF = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');

async function connecter(page, email = 'admin@creaself.com', mdp = 'admin1234') {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', mdp);
  await page.press('input[name="password"]', 'Enter');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
}
const fichiers = () => new Set(readdirSync(DOSSIER));
async function envoyer(page, id, fichier) {
  await page.goto(`/admin/produit/${id}/edit`);
  await page.setInputFiles('input[type="file"]', fichier);
  await page.locator('.action-saveAndReturn').first().click();
  await page.waitForLoadState('networkidle');
}

test('image : JPEG/PNG/WebP acceptés avec un nom aléatoire ; GIF, faux PNG et fichier trop lourd refusés', async ({ page }) => {
  await connecter(page);
  const avant = fichiers();
  const original = valeur(`SELECT image FROM produit WHERE id = ${COOKIE.noisette}`);
  const nouveaux = () => [...fichiers()].filter((f) => !avant.has(f));

  try {
    // Refus : format GIF, faux PNG (texte), trop lourd
    for (const [fichier, motif] of [
      [{ name: 'anim.gif', mimeType: 'image/gif', buffer: GIF }, /non autorisé/],
      [{ name: 'faux.png', mimeType: 'image/png', buffer: Buffer.from('ceci n\'est pas une image') }, /non autorisé|valide/],
      [{ name: 'gros.png', mimeType: 'image/png', buffer: Buffer.concat([PNG, Buffer.alloc(3 * 1024 * 1024)]) }, /trop lourd|large|taille/i],
    ]) {
      await envoyer(page, COOKIE.noisette, fichier);
      expect(await page.locator('body').evaluate((n) => n.textContent), fichier.name).toMatch(motif);
      expect(valeur(`SELECT image FROM produit WHERE id = ${COOKIE.noisette}`)).toBe(original);
      expect(nouveaux()).toHaveLength(0);
    }

    // Accepté : PNG, renommé au hasard (jamais le nom envoyé)
    await envoyer(page, COOKIE.noisette, { name: 'MA PHOTO ../../evil.png', mimeType: 'image/png', buffer: PNG });
    const image = valeur(`SELECT image FROM produit WHERE id = ${COOKIE.noisette}`);
    expect(image).toMatch(/^[a-f0-9]+\.png$/);
    expect(image).not.toContain('evil');
    expect(nouveaux()).toEqual([image]);
    expect((await page.request.get(`/asset/images/produits/${image}`)).status()).toBe(200);
  } finally {
    for (const f of nouveaux()) rmSync(path.join(DOSSIER, f), { force: true }); // nettoyage : on ne laisse rien dans le dépôt
    executerSql(`UPDATE produit SET image = '${original}' WHERE id = ${COOKIE.noisette}`);
  }
});

async function jetonExport(page) {
  await page.goto('/admin/export');
  return page.locator('input[name="_token"]').inputValue();
}
async function csv(page, jeton, form) {
  const r = await page.request.post('/admin/export/telecharger', { form: { _token: jeton, ...form } });
  expect(r.status()).toBe(200);
  expect(r.headers()['content-type']).toContain('text/csv');
  expect(r.headers()['content-disposition']).toContain('attachment');

  return (await r.body()).toString('utf8');
}

async function commande(browser, { payee = true, quantite = 1 } = {}) {
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const page = await contexte.newPage();
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page, 'livraison');
  if (payee) await poster(page.request, paiement(c));
  await contexte.close();

  return c;
}

test('export CSV : Excel français, payées par défaut, filtre de dates, ventes par article, injection de formules neutralisée, tracé', async ({ page, browser }) => {
  const a = await commande(browser, { quantite: 2 });
  const b = await commande(browser, { payee: false }); // en attente
  const numA = valeur(`SELECT numero_commande FROM commande WHERE id = ${a.id}`);
  const numB = valeur(`SELECT numero_commande FROM commande WHERE id = ${b.id}`);
  const prenomOriginal = valeur(`SELECT prenom FROM user WHERE email = '${process.env.E2E_USER_EMAIL}'`);
  executerSql(`UPDATE user SET prenom = '=HYPERLINK("http://pirate.test")' WHERE email = '${process.env.E2E_USER_EMAIL}'`);

  await connecter(page);
  const jeton = await jetonExport(page);

  // Commandes payées par défaut
  let t = await csv(page, jeton, { type: 'commandes' });
  expect(t.charCodeAt(0)).toBe(0xfeff); // BOM UTF-8
  const ligneEntete = t.slice(1).split('\r\n')[0];
  expect(ligneEntete).toBe('Numéro;Date;Statut;Client;E-mail;Mode;Articles;Total HT;TVA;Total TTC;Frais de livraison;Remboursé;Transporteur;Numéro de suivi;Facture');
  expect(t).toContain(numA);
  expect(t).not.toContain(numB);
  const ligneA = t.split('\r\n').find((l) => l.includes(numA));
  const cellules = ligneA.split(';');
  expect(cellules[2]).toBe('Payée');
  expect(cellules[9]).toMatch(/^\d+,\d{2}$/); // total TTC à la virgule
  expect(t).toContain(`'=HYPERLINK`); // formule neutralisée (apostrophe devant)
  expect(t).not.toMatch(/;=HYPERLINK/);
  expect(cellules.at(-1)).toMatch(/^F-\d{4}-000001$/);

  // Avec les non payées
  t = await csv(page, jeton, { type: 'commandes', toutes: '1' });
  expect(t).toContain(numB);

  // Filtre de dates (demain : rien ; aujourd'hui : tout)
  const demain = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
  const aujourdhui = new Date().toISOString().slice(0, 10);
  t = await csv(page, jeton, { type: 'commandes', debut: demain });
  expect(t).not.toContain(numA);
  t = await csv(page, jeton, { type: 'commandes', debut: aujourdhui, fin: aujourdhui });
  expect(t).toContain(numA);

  // Ventes : une ligne par article
  t = await csv(page, jeton, { type: 'ventes' });
  expect(t.slice(1).split('\r\n')[0]).toBe('Commande;Date;Statut;Article;Type;Quantité;Prix unitaire TTC;Taux TVA (%);Total TTC;Total HT');
  const vente = t.split('\r\n').find((l) => l.includes(numA)).split(';');
  expect(vente[3]).toContain('Pistache');
  expect(vente[4]).toBe('Cookie');
  expect(vente[5]).toBe('2');
  expect(vente[7]).toBe('5,50');

  // Chaque export est tracé dans le journal
  const entrees = lignes("SELECT auteur, action, libelle FROM journal_admin WHERE action = 'export' ORDER BY id");
  expect(entrees.length).toBe(5);
  expect(entrees[0]).toEqual(['admin@creaself.com', 'export', 'Export CSV des commandes']);

  // Sans jeton : refusé
  expect((await page.request.post('/admin/export/telecharger', { form: { type: 'commandes' } })).status()).toBe(403);
  executerSql(`UPDATE user SET prenom = '${prenomOriginal.replace(/'/g, "''")}' WHERE email = '${process.env.E2E_USER_EMAIL}'`);
});

test('export CSV : réservé à l\'administrateur (gestionnaire et visiteur refusés)', async ({ page }) => {
  creerAutreClient();
  executerSql(`UPDATE user SET roles = '["ROLE_GESTIONNAIRE"]' WHERE email = 'autre@perline.test'`);
  await connecter(page, 'autre@perline.test', process.env.E2E_USER_MDP);
  expect((await page.request.get('/admin/export')).status()).toBe(403);
  expect((await page.request.post('/admin/export/telecharger', { form: { type: 'commandes' } })).status()).toBe(403);

  const visiteur = await request.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  expect([301, 302, 401, 403]).toContain((await visiteur.get('/admin/export', { maxRedirects: 0 })).status());
  await visiteur.dispose();
});
