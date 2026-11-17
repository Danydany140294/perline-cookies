// Allergènes : saisie dans l'administration (14 cases), fiche produit, box (calcul automatique), page de paiement, information manquante.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE, BOX } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import { expect, ajouterCookie, ajouterBox } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

async function connecterAdmin(page) {
  await page.goto('/login');
  await page.fill('input[name="email"]', 'admin@creaself.com');
  await page.fill('input[name="password"]', 'admin1234');
  await page.press('input[name="password"]', 'Enter');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
}

test('l\'administrateur coche les allergènes d\'un cookie : fiche produit, box (union) et journal', async ({ browser }) => {
  executerSql(`UPDATE produit SET allergenes = '["gluten","oeufs","lait"]'`);
  const contexte = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: process.env.E2E_BASE_URL });
  const page = await contexte.newPage();
  await connecterAdmin(page);

  await page.goto(`/admin/produit/${COOKIE.noisette}/edit`);
  await expect(page.locator('input[name="Produit[allergenes][]"]')).toHaveCount(14);
  await page.locator('input[name="Produit[allergenes][]"][value="fruits_a_coque"]').check({ force: true });
  await page.locator('.action-saveAndReturn').first().click();
  await page.waitForLoadState('networkidle');
  expect(JSON.parse(valeur(`SELECT allergenes FROM produit WHERE id = ${COOKIE.noisette}`))).toEqual(['gluten', 'oeufs', 'lait', 'fruits_a_coque']);
  expect(valeur("SELECT COUNT(*) FROM journal_admin WHERE avant LIKE '%Allergènes%'")).toBe('1');

  const visiteur = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: process.env.E2E_BASE_URL });
  const site = await visiteur.newPage();
  await site.goto('/produits');
  await expect(site.locator(`#product-${COOKIE.noisette} [data-allergenes]`)).toContainText('Fruits à coque');
  await expect(site.locator(`#product-${COOKIE.pistache} [data-allergenes]`)).toContainText('Gluten, Œufs, Lait');
  await expect(site.locator(`#product-${COOKIE.pistache} [data-allergenes]`)).not.toContainText('Fruits à coque');

  await site.goto('/box');
  expect(await site.locator('body').evaluate((n) => n.textContent)).toMatch(/Allergènes[^:]*:\s*Gluten, Œufs, Lait, Fruits à coque/);
  await contexte.close();
  await visiteur.close();
});

test('cookie non renseigné : jamais « sans allergène » ; cookie sans allergène : mention explicite', async ({ browser }) => {
  executerSql(`UPDATE produit SET allergenes = NULL WHERE id = ${COOKIE.pistache}; UPDATE produit SET allergenes = '[]' WHERE id = ${COOKIE.peche}`);
  const contexte = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: process.env.E2E_BASE_URL });
  const site = await contexte.newPage();
  await site.goto('/produits');
  await expect(site.locator(`#product-${COOKIE.pistache} [data-allergenes]`)).toContainText('contactez-nous');
  await expect(site.locator(`#product-${COOKIE.pistache} [data-allergenes]`)).not.toContainText('aucun des 14');
  await expect(site.locator(`#product-${COOKIE.peche} [data-allergenes]`)).toContainText('aucun des 14 allergènes');
  await contexte.close();
});

test('page de paiement : allergènes des articles du panier (cookie et box)', async ({ page }) => {
  executerSql(`UPDATE produit SET allergenes = '["gluten","oeufs","lait"]'; UPDATE produit SET allergenes = '["arachides"]' WHERE id = ${COOKIE.macadamia}`);
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  await ajouterBox(page.request, BOX.gourmande, 1);
  await page.goto('/paiement');
  const bloc = page.locator('#recap-allergenes');
  await expect(bloc).toContainText('Cookie Pistache Cannelle');
  await expect(bloc).toContainText('Gluten, Œufs, Lait.');
  await expect(bloc).toContainText('Arachides'); // la Box Gourmande contient du Macadamia
});
