// Sécurité : l'administrateur ne peut jamais passer une commande « Payée » à la main (tous les paiements passent par Stripe).
import { test } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { expect, ajouterCookie, cliquerSurPayer, commande as lireCommande } from '../lib/aide.js';
import { lignes } from '../lib/db.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(() => reinitialiser());

test.describe('administration', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  async function connecterAdmin(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', 'admin@creaself.com');
    await page.fill('input[name="password"]', 'admin1234');
    await page.press('input[name="password"]', 'Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });
  }

  async function commandeEnAttente(browser) {
    const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const page = await contexte.newPage();
    await ajouterCookie(page.request, COOKIE.pistache, 1);
    const c = await cliquerSurPayer(page, 'livraison');
    await contexte.close();
    expect(c).not.toBeNull();

    return c;
  }

  test('« Payée » n\'est pas proposé depuis « En attente » et une requête forgée est refusée', async ({ page, browser }) => {
    const c = await commandeEnAttente(browser);
    await connecterAdmin(page);
    await page.goto(`/admin/commande/${c.id}/edit`);
    const select = page.locator('select[name="Commande[statut]"]');
    await expect(select).toBeAttached();

    const options = await select.locator('option').allTextContents();
    expect(options.map((o) => o.trim())).not.toContain('Payée');
    expect(options.map((o) => o.trim())).toContain('Annulée');

    // Requête forgée : on ajoute l'option à la main puis on enregistre
    await select.evaluate((s) => { const o = document.createElement('option'); o.value = 'payee'; o.textContent = 'Payée'; s.appendChild(o); s.value = 'payee'; });
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');

    expect(lireCommande(c.id).statut).toBe('en_attente');
    expect(lignes(`SELECT COUNT(*) FROM commande_historique WHERE commande_id = ${c.id} AND nouveau_statut = 'payee'`)[0][0]).toBe('0');
  });

  test('le webhook Stripe, lui, passe bien la commande « Payée »', async ({ page, browser }) => {
    const c = await commandeEnAttente(browser);

    const r = await poster(page.request, paiement(c));

    expect(r.corps.status).toBe('payee');
    expect(lignes(`SELECT source FROM commande_historique WHERE commande_id = ${c.id} AND nouveau_statut = 'payee'`)[0][0]).toBe('webhook_stripe');
  });
});
