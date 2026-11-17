// Chapitre 1.5, bloc 1 : plus de fausse preuve sociale ; favoris, avis et points de fidélité masqués par défaut (interrupteurs de config/boutique.yaml).
import { test } from '@playwright/test';
import { reinitialiser } from '../lib/etat.js';
import { expect } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

test('accueil : aucune mention chiffrée inventée (avis, note, étoiles, témoignage)', async ({ page }) => {
  await page.goto('/');
  const texte = await page.locator('body').evaluate((n) => n.textContent);
  expect(texte).not.toMatch(/2000 avis|4,9\s*\/\s*5|★|Cliente vérifiée|avis clients/);
});

test('compte : favoris et points de fidélité masqués par défaut', async ({ page }) => {
  await page.goto('/user/mon-compte');
  await expect(page.locator('.favorites-section')).toHaveCount(0);
  await expect(page.locator('.card-loyalty')).toHaveCount(0);
  await expect(page.locator('.info-loyalty-card')).toHaveCount(0);
  expect(await page.locator('body').evaluate((n) => n.textContent)).not.toContain('Miettes d\'Or');
});

test('interrupteurs activés (serveur de test 2) : favoris et points de fidélité réapparaissent, rien n\'a été supprimé', async ({ page }) => {
  await page.goto(`${process.env.E2E_BASE_URL2}/user/mon-compte`);
  await expect(page.locator('.favorites-section')).toHaveCount(1);
  await expect(page.locator('.card-loyalty')).toHaveCount(1);
  await expect(page.locator('.info-loyalty-card')).toHaveCount(1);
});
