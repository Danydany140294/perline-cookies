// Suppression de l'onglet « Moyens de paiement » : il ne stockait rien et n'était pas fonctionnel (paiements = Stripe Checkout).
import { test } from '@playwright/test';
import { reinitialiser } from '../lib/etat.js';
import { expect } from '../lib/aide.js';
import { valeur } from '../lib/db.js';

test.beforeEach(() => reinitialiser());

test('le tableau de bord n\'a plus d\'onglet ni de bouton « Moyens de paiement »', async ({ page }) => {
  await page.goto('/user/mon-compte');
  await expect(page.locator('a.nav-item[data-tab="paiement"]')).toHaveCount(0);
  await expect(page.locator('#tab-paiement')).toHaveCount(0);
  await expect(page.locator('#btn-add-card, #btn-add-apple-pay')).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText('Moyens de Paiement');
});

test('les anciennes routes /mon-compte/moyens-paiement/* n\'existent plus et la table a disparu', async ({ page }) => {
  for (const [methode, url] of [['post', '/mon-compte/moyens-paiement/ajouter-carte'], ['post', '/mon-compte/moyens-paiement/configurer-apple-pay'],
    ['post', '/mon-compte/moyens-paiement/definir-defaut/1'], ['delete', '/mon-compte/moyens-paiement/supprimer/1']]) {
    const reponse = await page.request[methode](url, { maxRedirects: 0 });
    expect(reponse.status(), `${methode} ${url}`).toBe(404);
  }
  expect(valeur("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'moyen_paiement'")).toBe('0');
});
