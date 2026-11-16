// Une seule gestion des adresses : /compte/adresses. L'onglet « Mes Adresses » du tableau de bord renvoie vers cette page
// (l'ancien formulaire dupliqué, mal mis en forme, a été supprimé).
import { test } from '@playwright/test';
import { reinitialiser } from '../lib/etat.js';
import { expect } from '../lib/aide.js';
import { valeur } from '../lib/db.js';

test.beforeEach(() => reinitialiser());

test('le tableau de bord ne contient plus de formulaire d\'adresse : il renvoie vers /compte/adresses', async ({ page }) => {
  await page.goto('/user/mon-compte');

  await expect(page.locator('#tab-adresses form')).toHaveCount(0);
  await expect(page.locator('#address-form-container')).toHaveCount(0);
  await expect(page.locator('input[name^="adresse["]')).toHaveCount(0);
  const lien = page.locator('#lien-gerer-adresses');
  await expect(lien).toHaveAttribute('href', '/compte/adresses');

  await page.goto('/user/mon-compte');
  await page.locator('a.nav-item[data-tab="adresses"]').click();
  await lien.click();
  await expect(page).toHaveURL(/\/compte\/adresses$/);
  await expect(page.locator('input[name="adresse[rue]"]')).toBeVisible();
  await expect(page.locator('input[name="adresse[telephone]"]')).toBeVisible();
});

test('l\'ancienne création d\'adresse par le tableau de bord n\'existe plus : un POST ne crée rien', async ({ page }) => {
  const avant = valeur('SELECT COUNT(*) FROM adresse');
  const reponse = await page.request.post('/user/mon-compte', { form: { 'adresse[rue]': 'x', 'adresse[ville]': 'y' }, maxRedirects: 0 });
  expect(reponse.status()).toBeGreaterThanOrEqual(400); // méthode refusée
  expect(valeur('SELECT COUNT(*) FROM adresse')).toBe(avant);
});