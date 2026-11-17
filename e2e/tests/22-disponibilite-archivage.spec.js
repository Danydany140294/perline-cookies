// Chapitre 1.6, bloc 2 : disponibilité calculée (actif ET stock > 0), désactivation à la place de la suppression, suppressions bloquées avec message.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE, BOX } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, ajouterBox, derniereCommande, cliquerSurPayer } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

const NOM = 'Cookie Pistache Cannelle';
const catalogue = async (page) => { await page.goto('/produits'); return page.locator('body').evaluate((n) => n.textContent); };

test.describe('administration', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  async function connecterAdmin(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', 'admin@creaself.com');
    await page.fill('input[name="password"]', 'admin1234');
    await page.press('input[name="password"]', 'Enter');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
  }
  async function enregistrer(page) {
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');
  }

  test('rupture calculée : à 0 le produit reste affiché « Indisponible » (achat désactivé) et redevient achetable tout seul au réapprovisionnement ; « actif » reste intact', async ({ page }) => {
    await connecterAdmin(page);
    await page.goto('/produits');
    await expect(page.locator(`#product-${COOKIE.pistache} button[type="submit"]`)).toHaveCount(1);

    executerSql(`UPDATE produit SET stock = 0 WHERE id = ${COOKIE.pistache}`); // plus de stock (par exemple après des ventes)
    await page.goto('/produits');
    await expect(page.locator(`#product-${COOKIE.pistache}`)).toContainText('Indisponible');
    await expect(page.locator(`#product-${COOKIE.pistache} button[type="submit"]`)).toHaveCount(0);
    expect(valeur(`SELECT is_active FROM produit WHERE id = ${COOKIE.pistache}`)).toBe('1'); // « actif » n'a pas été touché

    // Réapprovisionnement depuis l'administration : remis en vente sans aucun autre geste
    await page.goto(`/admin/produit/${COOKIE.pistache}/edit`);
    await page.fill('[name="Produit[stock]"]', '12');
    await enregistrer(page);
    await page.goto('/produits');
    await expect(page.locator(`#product-${COOKIE.pistache} button[type="submit"]`)).toHaveCount(1);
  });

  test('désactivation par l\'administrateur : retiré du site, refusé au panier, et un réapprovisionnement ne le réactive jamais', async ({ page, browser }) => {
    await connecterAdmin(page);
    await page.goto(`/admin/produit/${COOKIE.pistache}/edit`);
    await page.locator('[name="Produit[isActive]"]').uncheck({ force: true });
    await enregistrer(page);
    expect(await catalogue(page)).not.toContain(NOM);

    const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const client = await contexte.newPage();
    const refus = await ajouterCookie(client.request, COOKIE.pistache, 1).catch((e) => e);
    await contexte.close();
    expect(valeur("SELECT COUNT(*) FROM ligne_panier WHERE produit_id = " + COOKIE.pistache)).toBe('0');

    await page.goto(`/admin/produit/${COOKIE.pistache}/edit`);
    await page.fill('[name="Produit[stock]"]', '99');
    await enregistrer(page);
    expect(await catalogue(page)).not.toContain(NOM);
    expect(valeur(`SELECT is_active FROM produit WHERE id = ${COOKIE.pistache}`)).toBe('0');

    // Réactivation explicite : de retour
    await page.goto(`/admin/produit/${COOKIE.pistache}/edit`);
    await page.locator('[name="Produit[isActive]"]').check({ force: true });
    await enregistrer(page);
    expect(await catalogue(page)).toContain(NOM);
  });

  test('box désactivée : absente de la page des boxes et refusée au panier', async ({ page, browser }) => {
    executerSql(`UPDATE box SET is_active = 0 WHERE id = ${BOX.gourmande}`);
    await page.goto('/box');
    expect(await page.locator('body').evaluate((n) => n.textContent)).not.toContain('Box Gourmande');

    const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const client = await contexte.newPage();
    await ajouterBox(client.request, BOX.gourmande, 1).catch(() => {});
    await contexte.close();
    expect(valeur(`SELECT COUNT(*) FROM ligne_panier WHERE box_id = ${BOX.gourmande}`)).toBe('0');
  });

  test('suppressions : pas de bouton ni de route pour cookies et boxes ; commandes et utilisateurs refusés avec un message clair (jamais d\'erreur SQL)', async ({ page, browser }) => {
    // Une commande (avec lignes et historique) et un utilisateur ayant des commandes
    const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const client = await contexte.newPage();
    await ajouterCookie(client.request, COOKIE.pistache, 1);
    const c = await cliquerSurPayer(client, 'retrait');
    await contexte.close();
    const idUser = valeur(`SELECT user_id FROM commande WHERE id = ${c.id}`);

    await connecterAdmin(page);
    const nbProduits = valeur('SELECT COUNT(*) FROM produit');
    for (const url of ['/admin/produit/1/delete', '/admin/box/1/delete']) {
      expect((await page.request.post(url)).status(), url).toBe(403);
    }
    expect(valeur('SELECT COUNT(*) FROM produit')).toBe(nbProduits);

    // Commandes : le jeton de suppression est dans la page de liste
    await page.goto('/admin/commande');
    const jeton = await page.locator('form#action-confirmation-form input[name="token"], form#delete-form input[name="token"]').first().inputValue();
    const rep = await page.request.post(`/admin/commande/${c.id}/delete`, { form: { token: jeton }, maxRedirects: 0 });
    expect([200, 302, 303]).toContain(rep.status());
    expect(valeur(`SELECT COUNT(*) FROM commande WHERE id = ${c.id}`)).toBe('1');
    await page.goto('/admin/commande');
    await expect(page.locator('body')).toContainText('ne peut pas être supprimée');

    // Utilisateurs
    await page.goto('/admin/user');
    const jetonUser = await page.locator('form#action-confirmation-form input[name="token"], form#delete-form input[name="token"]').first().inputValue();
    await page.request.post(`/admin/user/${idUser}/delete`, { form: { token: jetonUser }, maxRedirects: 0 });
    expect(valeur(`SELECT COUNT(*) FROM user WHERE id = ${idUser}`)).toBe('1');
    await page.goto('/admin/user');
    await expect(page.locator('body')).toContainText('ne peut pas être supprimé');
    await expect(page.locator('body')).not.toContainText('SQLSTATE');
  });
});
