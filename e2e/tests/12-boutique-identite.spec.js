// Bloc 1 du chapitre 1.4 : identité de la boutique (config/boutique.yaml) et mode démonstration.
import { test } from '@playwright/test';
import { expect } from '../lib/aide.js';

test('mode démo actif (serveur principal) : bandeau « Version de démonstration » sur le site', async ({ page }) => {
  await page.goto('/mentions-legales');
  await expect(page.locator('.bandeau-demo')).toHaveCount(1);
  await expect(page.locator('.bandeau-demo')).toContainText('Version de démonstration');
});

test('mode démo inactif (BOUTIQUE_MODE_DEMO=0) : aucun bandeau, tout est réel', async ({ page }) => {
  await page.goto(`${process.env.E2E_BASE_URL2}/mentions-legales`);
  await expect(page.locator('.bandeau-demo')).toHaveCount(0);
});

test('mentions légales, CGV, pied de page et contact affichent l\'identité de la configuration', async ({ page }) => {
  await page.goto('/mentions-legales');
  const texte = await page.locator('body').evaluate((n) => n.textContent);
  expect(texte).toContain('Perline Cookies SAS (exemple)');
  expect(texte).toContain('000 000 000 00000');
  expect(texte).toContain('FR00 000000000');
  expect(texte).not.toContain('Sucre Holographique');

  await page.goto('/contact');
  expect(await page.locator('body').evaluate((n) => n.textContent)).toContain('contact@exemple.invalid');
});
