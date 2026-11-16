// Bloc 3 du chapitre 1.3 : délais en jours ouvrés, affichés avant paiement et rappelés dans l'e-mail et le compte.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { executerSql, valeur, lignes } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer, colonneCommande, emails, viderEmails } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

const PHRASE = /Livraison estimée entre le \p{L}+ \d{1,2} \p{L}+ et le \p{L}+ \d{1,2} \p{L}+/u;

test('panier : période de livraison estimée et jours d\'expédition (produits frais) affichés', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);

  await page.goto('/panier');

  await expect(page.locator('.trust-badges')).toContainText('Livraison estimée entre le');
  await expect(page.locator('.trust-badges')).toContainText('Expédition le lundi, mardi et mercredi');
  expect(await page.locator('.trust-badges').innerText()).toMatch(PHRASE);
});

test('modale du panier : la livraison estimée apparaît avec le panier', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  await page.goto('/');

  await page.evaluate(() => panierModal.open());

  await expect(page.locator('#deliveryEstimate')).toBeVisible();
  await expect(page.locator('#deliveryEstimate')).toContainText('Livraison estimée entre le');
});

test('page de paiement : la période est annoncée avant validation (livraison), et le message de retrait en retrait', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  await page.goto('/paiement');

  await expect(page.locator('#recap-estimation')).toContainText('Livraison estimée entre le');
  expect(await page.locator('.mode-estimation').first().innerText()).toMatch(PHRASE);
  await expect(page.locator('.mode-estimation').first()).toContainText('ne passe pas le week-end en entrepôt');

  await page.check('input[name="mode"][value="retrait"]');
  await expect(page.locator('#recap-estimation')).not.toContainText('Livraison estimée entre');
  await expect(page.locator('#recap-estimation')).toContainText('Commande prête sous 24 h');
});

test('la période annoncée est mémorisée sur la commande, en jours ouvrés, et reprise dans l\'e-mail et le compte', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  await page.goto('/paiement');
  const annoncee = (await page.locator('#recap-estimation').innerText()).replace('🚚', '').trim();
  const c = await cliquerSurPayer(page, 'livraison', { naviguer: false });

  const debut = colonneCommande(c.id, 'livraison_estimee_debut');
  const fin = colonneCommande(c.id, 'livraison_estimee_fin');
  expect(debut).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(fin >= debut).toBe(true);
  // Jours ouvrés : lundi à vendredi (DAYOFWEEK MySQL : 1 = dimanche, 7 = samedi)
  for (const jour of [debut, fin]) {
    const n = Number(valeur(`SELECT DAYOFWEEK('${jour}')`));
    expect(n).toBeGreaterThanOrEqual(2);
    expect(n).toBeLessThanOrEqual(6);
  }

  await poster(page.request, paiement(c));
  await expect.poll(async () => (await emails()).length).toBe(1);
  const detail = await (await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/message/${(await emails())[0].ID}`)).json();
  expect(detail.HTML).toContain(annoncee); // même période que celle vue avant le paiement

  await page.goto('/user/mon-compte');
  await expect(page.locator('body')).toContainText(annoncee);
});

test('les jours de fermeture ne comptent pas : serveur configuré fermé pendant 15 jours et expédiant du lundi au vendredi', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const serveurFerme = process.env.E2E_BASE_URL2;
  await page.goto(`${serveurFerme}/paiement`);
  await page.waitForLoadState('networkidle');

  const c = await cliquerSurPayer(page, 'livraison', { naviguer: false });

  const debut = colonneCommande(c.id, 'livraison_estimee_debut');
  const limite = valeur('SELECT DATE_ADD(CURDATE(), INTERVAL 14 DAY)');
  expect(debut > limite).toBe(true); // la livraison ne peut commencer qu'après la fermeture de l'atelier
});

test('les CGV et la FAQ annoncent les mêmes délais que la configuration', async ({ page }) => {
  await page.goto('/conditions-generales-de-vente');
  const cgv = await page.locator('body').innerText();
  expect(cgv).toContain('48 heures ouvrées');
  expect(cgv).toContain('le lundi, mardi et mercredi');
  expect(cgv).toContain('2 à 5 jours ouvrés');

  await page.goto('/');
  const accueil = (await page.locator('body').textContent()).replace(/\s+/g, ' '); // textContent : la FAQ est repliée (masquée)
  expect(accueil).toContain('Préparation sous 48 h ouvrées');
  expect(accueil).not.toContain('48/72h');
});
