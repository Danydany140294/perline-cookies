// Sécurité : l'administrateur ne peut jamais passer une commande « Payée » à la main (tous les paiements passent par Stripe).
import { test } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { expect, ajouterCookie, cliquerSurPayer, commande as lireCommande } from '../lib/aide.js';
import { executerSql, lignes } from '../lib/db.js';
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
  async function forcerOption(page, valeur, libelle) {
    await page.locator('select[name="Commande[statut]"]').evaluate((s, [v, l]) => { const o = document.createElement('option'); o.value = v; o.textContent = l; s.appendChild(o); s.value = v; }, [valeur, libelle]);
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');
  }

  test('« Remboursée » et « À vérifier » ne sont jamais proposés à l\'admin (même sur une commande livrée) et une requête forgée est refusée', async ({ page, browser }) => {
    const c = await commandeEnAttente(browser);
    await poster(page.request, paiement(c));
    executerSql(`UPDATE commande SET statut = 'livree' WHERE id = ${c.id}`);
    await connecterAdmin(page);
    await page.goto(`/admin/commande/${c.id}/edit`);
    const select = page.locator('select[name="Commande[statut]"]');
    await expect(select).toBeAttached();

    const options = (await select.locator('option').allTextContents()).map((o) => o.trim());
    expect(options).not.toContain('Remboursée');
    expect(options).not.toContain('À vérifier');

    await forcerOption(page, 'remboursee', 'Remboursée');
    expect(lireCommande(c.id).statut).toBe('livree');
    await page.goto(`/admin/commande/${c.id}/edit`);
    await forcerOption(page, 'a_verifier', 'À vérifier');
    expect(lireCommande(c.id).statut).toBe('livree');
  });

  test('« À vérifier » → « Payée » reste possible pour l\'admin, mais la note est obligatoire et inscrite dans l\'historique', async ({ page, browser }) => {
    const c = await commandeEnAttente(browser);
    const ecart = await poster(page.request, paiement(c, { montant: 100 })); // 1,00 € payé au lieu du total : écart
    expect(ecart.corps.status).toBe('a_verifier');
    await connecterAdmin(page);

    // Sans note : refusé
    await page.goto(`/admin/commande/${c.id}/edit`);
    const select = page.locator('select[name="Commande[statut]"]');
    await expect(select).toBeAttached();
    expect((await select.locator('option').allTextContents()).map((o) => o.trim())).toContain('Payée');
    await select.selectOption({ label: 'Payée' }, { force: true });
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText('comment le paiement a été vérifié');
    expect(lireCommande(c.id).statut).toBe('a_verifier');

    // Avec une note : accepté, tracé (source admin + note)
    await page.goto(`/admin/commande/${c.id}/edit`);
    await page.locator('select[name="Commande[statut]"]').selectOption({ label: 'Payée' }, { force: true });
    await page.fill('[name="Commande[noteChangementStatut]"]', 'Paiement vérifié dans Stripe : écart expliqué par une remise accordée');
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');

    expect(lireCommande(c.id).statut).toBe('payee');
    const [source, note] = lignes(`SELECT source, note FROM commande_historique WHERE commande_id = ${c.id} AND nouveau_statut = 'payee' ORDER BY id DESC LIMIT 1`)[0];
    expect(source).toBe('admin');
    expect(note).toContain('Paiement vérifié dans Stripe');
  });
});
