// Chapitre 1.6, finition (réponses de Dany) : rupture affichée « Indisponible », CA par date de facture, seuil « inférieur ou égal »,
// « À vérifier » dans les alertes, e-mail client en lecture seule + modification tracée, TVA réservée à l'administrateur, page 403 propre.
import { test } from '@playwright/test';
import { reinitialiser, creerAutreClient, COOKIE } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(() => reinitialiser());

const MDP = process.env.E2E_USER_MDP;
async function connecter(page, email = 'admin@creaself.com', mdp = 'admin1234') {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', mdp);
  await page.press('input[name="password"]', 'Enter');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
}
async function commandePayee(browser, quantite = 1) {
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const page = await contexte.newPage();
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page, 'livraison');
  await poster(page.request, paiement(c));
  await contexte.close();
  return c;
}
const enregistrer = async (page) => { await page.locator('.action-saveAndReturn').first().click(); await page.waitForLoadState('networkidle'); };

test('un cookie en rupture reste affiché avec « Indisponible » et achat désactivé ; un cookie désactivé disparaît', async ({ page }) => {
  executerSql(`UPDATE produit SET stock = 0 WHERE id = ${COOKIE.pistache}; UPDATE produit SET is_active = 0 WHERE id = ${COOKIE.peche}`);
  await page.goto('/produits');
  const carte = page.locator(`#product-${COOKIE.pistache}`);
  await expect(carte).toHaveCount(1);
  await expect(carte).toContainText('Indisponible');
  await expect(carte.locator('button[disabled]')).toHaveCount(1);
  await expect(carte.locator('button[type="submit"]')).toHaveCount(0);
  await expect(page.locator(`#product-${COOKIE.peche}`)).toHaveCount(0); // désactivé : jamais affiché

  await page.goto('/');
  await expect(page.locator('.badge-indisponible')).toHaveCount(1);

  // Box dont un cookie est en rupture : affichée, indisponible
  await page.goto('/box');
  expect(await page.locator('body').evaluate((n) => n.textContent)).toMatch(/Indisponible/);
});

test('chiffre d\'affaires et commandes du jour : date de PAIEMENT (émission de la facture)', async ({ page, browser }) => {
  const a = await commandePayee(browser, 2);
  const b = await commandePayee(browser, 1);
  executerSql(`UPDATE facture SET date_emission = DATE_SUB(date_emission, INTERVAL 40 DAY) WHERE commande_id = ${a.id}`); // payée il y a 40 jours
  const ttcB = Number(valeur(`SELECT total_ttc FROM commande WHERE id = ${b.id}`));
  await connecter(page);
  await page.goto('/admin');
  await expect(page.locator('#stat-commandes-jour')).toHaveText('1');
  const jour = await page.locator('#ca-jour-ttc').evaluate((n) => n.textContent.replace(/[  \s]/g, ''));
  expect(jour).toContain(ttcB.toFixed(2).replace('.', ','));
});

test('alerte de stock : déclenchée quand le stock est ÉGAL au seuil ; « À vérifier » dans les alertes du tableau de bord', async ({ page, browser }) => {
  const a = await commandePayee(browser, 1);
  executerSql(`UPDATE commande SET statut = 'a_verifier' WHERE id = ${a.id}`);
  await connecter(page);
  await page.goto(`/admin/produit/${COOKIE.noisette}/edit`);
  await page.fill('[name="Produit[stock]"]', '5'); // seuil global 5 : atteint
  await enregistrer(page);
  expect(valeur(`SELECT alerte_stock_envoyee FROM produit WHERE id = ${COOKIE.noisette}`)).toBe('1');
  await page.goto('/admin');
  await expect(page.locator('.alerte-a-verifier')).toContainText(valeur(`SELECT numero_commande FROM commande WHERE id = ${a.id}`));
  await expect(page.locator('.alerte-stock')).toContainText('Noisette');
});

test('client : e-mail en lecture seule pour l\'admin, modification tracée sans la valeur ; TVA invisible au gestionnaire, prix modifiable ; page 403 propre', async ({ page, browser }) => {
  creerAutreClient();
  const id = valeur("SELECT id FROM user WHERE email = 'autre@perline.test'");
  await connecter(page);

  await page.goto(`/admin/user/${id}/edit`);
  await expect(page.locator('[name="User[email]"]')).toBeDisabled();
  await page.fill('[name="User[nom]"]', 'Nouveau');
  await enregistrer(page);
  expect(valeur(`SELECT nom FROM user WHERE id = ${id}`)).toBe('Nouveau');
  expect(valeur(`SELECT email FROM user WHERE id = ${id}`)).toBe('autre@perline.test');
  const trace = lignes("SELECT auteur, action, entite, avant, apres FROM journal_admin WHERE entite = 'User' ORDER BY id DESC LIMIT 1")[0];
  expect(trace[0]).toBe('admin@creaself.com');
  expect(trace[1]).toBe('modification');
  expect(trace[3] + trace[4]).not.toContain('Nouveau'); // pas de donnée personnelle dans le journal
  expect(trace[4]).toContain('Nom');

  // TVA : visible et modifiable par l'administrateur
  await page.goto(`/admin/produit/${COOKIE.pistache}/edit`);
  await expect(page.locator('[name="Produit[tauxTva]"]')).toHaveCount(1);

  // Le gestionnaire : prix modifiable (tracé), TVA absente
  executerSql(`UPDATE user SET roles = '["ROLE_GESTIONNAIRE"]' WHERE id = ${id}`);
  const contexte = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  const g = await contexte.newPage();
  await connecter(g, 'autre@perline.test', MDP);
  await g.goto(`/admin/produit/${COOKIE.pistache}/edit`);
  await expect(g.locator('[name="Produit[tauxTva]"]')).toHaveCount(0);
  await g.fill('[name="Produit[prix]"]', '3.9');
  await enregistrer(g);
  expect(Number(valeur(`SELECT prix FROM produit WHERE id = ${COOKIE.pistache}`))).toBeCloseTo(3.9, 2);
  expect(Number(valeur(`SELECT taux_tva FROM produit WHERE id = ${COOKIE.pistache}`))).toBeCloseTo(5.5, 2);
  const prix = lignes("SELECT auteur, action FROM journal_admin WHERE entite = 'Produit' ORDER BY id DESC LIMIT 1")[0];
  expect(prix).toEqual(['autre@perline.test', 'modification']);

  // Page 403 propre (jamais une 500) sur les rubriques interdites
  for (const url of ['/admin/user', '/admin/journal-admin', '/admin/panier', '/admin/export', `/admin/user/${id}/edit`]) {
    const reponse = await g.goto(url);
    expect(reponse.status(), url).toBe(403);
    expect(await g.locator('body').evaluate((n) => n.textContent), url).toMatch(/403|refus|denied/i);
  }
  await contexte.close();
});
