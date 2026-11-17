// Pages légales : cohérentes avec le site réel, alimentées par les paramètres de la boutique (marque blanche).
import { test } from '@playwright/test';
import { reinitialiser } from '../lib/etat.js';
import { executerSql } from '../lib/db.js';
import { expect } from '../lib/aide.js';

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(() => reinitialiser());

const texte = async (page, url) => { await page.goto(url); return page.locator('.legal-content').evaluate((n) => n.textContent.replace(/\s+/g, ' ')); };

test('mentions légales : hébergeur réel (Hetzner), médiateur, plus d\'OVH', async ({ page }) => {
  const t = await texte(page, '/mentions-legales');
  expect(t).toContain('Hetzner Online GmbH');
  expect(t).toContain('Industriestr. 25, 91710 Gunzenhausen');
  expect(t).toContain('Finlande');
  expect(t).toContain('médiateur de la consommation');
  expect(t).not.toMatch(/OVH/);
  expect(await page.locator('.legal-date').textContent()).toContain('2026');
});

test('CGV : exclusion de la rétractation pour les denrées périssables, produit abîmé, allergènes, médiateur, factures et avoirs', async ({ page }) => {
  const t = await texte(page, '/conditions-generales-de-vente');
  expect(t).toContain('L.221-28');
  expect(t).toMatch(/ne peut pas être exercé pour les biens susceptibles de se détériorer/);
  expect(t).toMatch(/arrive abîmée, incomplète ou non conforme/);
  expect(t).toContain('dans les 48 heures');
  expect(t).toContain('sur la fiche de chaque produit');
  expect(t).toContain('page de paiement');
  expect(t).toContain('médiateur de la consommation');
  expect(t).toMatch(/facture au format PDF/);
  expect(t).toMatch(/avoir/);
  expect(t).toContain('Stripe');
  expect(t).not.toMatch(/seuls compétents/);
});

test('confidentialité : plus de données de carte, plus de fidélité (désactivée), sous-traitants réels, droits RGPD', async ({ page }) => {
  const t = await texte(page, '/politique-de-confidentialite');
  expect(t).not.toMatch(/4 derniers chiffres/);
  expect(t).not.toMatch(/Miettes d'Or|fidélité/);
  expect(t).not.toMatch(/OVH/);
  expect(t).toContain('Hetzner Online GmbH');
  expect(t).toContain('Stripe');
  expect(t).toMatch(/Mes données et suppression du compte/);
  expect(t).toMatch(/10 ans/);
});

test('cookies : uniquement des cookies nécessaires, ni Google Analytics ni bandeau d\'acceptation', async ({ page }) => {
  const t = await texte(page, '/gestion-des-cookies');
  expect(t).not.toMatch(/Google Analytics|analytiques/i);
  expect(t).toContain('PHPSESSID');
  expect(t).toMatch(/aucun cookie de mesure d'audience/);
  await page.goto('/');
  await expect(page.locator('#cookie-banner')).toBeVisible();
  await expect(page.locator('#cookie-banner button')).toHaveCount(1);
});

test('les pages légales suivent les paramètres : l\'hébergeur et le médiateur changent sans toucher au code', async ({ page }) => {
  executerSql(`INSERT INTO parametre_boutique (cle, valeur, modifie_le) VALUES
    ('boutique.hebergeur_nom', '"Autre Hébergeur SAS"', NOW()), ('boutique.mediateur', '"Médiateur Test, 1 rue du Litige"', NOW()),
    ('boutique.fonctions.points_fidelite', 'true', NOW())`);
  expect(await texte(page, '/mentions-legales')).toContain('Autre Hébergeur SAS');
  expect(await texte(page, '/conditions-generales-de-vente')).toContain('Médiateur Test, 1 rue du Litige');
  expect(await texte(page, '/politique-de-confidentialite')).toContain('programme de fidélité');
});
