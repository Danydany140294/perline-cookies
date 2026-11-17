// Création d'une adresse depuis /compte/adresses (régression : appel à une méthode inexistante, trouvé par PHPStan).
import { test } from '@playwright/test';
import { reinitialiser } from '../lib/etat.js';
import { valeur } from '../lib/db.js';
import { expect } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

test('ajouter une adresse depuis la page des adresses : enregistrée, sans erreur', async ({ page }) => {
  const avant = Number(valeur("SELECT COUNT(*) FROM adresse WHERE user_id = (SELECT id FROM user WHERE email = 'e2e@perline.test')"));
  await page.goto('/compte/adresses');
  await page.fill('input[name="adresse[nom]"]', 'Bureau');
  await page.fill('input[name="adresse[rue]"]', '5 avenue des Tests');
  await page.fill('input[name="adresse[codePostal]"]', '34070');
  await page.fill('input[name="adresse[ville]"]', 'Montpellier');
  const reponse = page.waitForResponse((r) => r.url().includes('/compte/adresses') && r.request().method() === 'POST');
  await page.locator('form[name="adresse"] button[type="submit"]').first().click();
  expect((await reponse).status()).toBeLessThan(400);
  await page.waitForLoadState('networkidle');
  expect(Number(valeur("SELECT COUNT(*) FROM adresse WHERE user_id = (SELECT id FROM user WHERE email = 'e2e@perline.test')"))).toBe(avant + 1);
  await expect(page.locator('body')).toContainText('Adresse ajoutée');
});
