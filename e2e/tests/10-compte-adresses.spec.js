// « Mes Adresses » : la MÊME vue que /compte/adresses (compte/_adresses_vue.html.twig), affichée directement dans l'onglet du
// tableau de bord, sans changer de page. Mêmes contrôles d'accès, jeton CSRF et validation (AdresseController).
import { test } from '@playwright/test';
import { reinitialiser, creerAutreClient } from '../lib/etat.js';
import { expect } from '../lib/aide.js';
import { valeur, executerSql } from '../lib/db.js';

test.beforeEach(() => reinitialiser());

const userId = () => valeur("SELECT id FROM user WHERE email = 'e2e@perline.test'");
const nb = () => Number(valeur(`SELECT COUNT(*) FROM adresse WHERE user_id = ${userId()}`));
const idAdresse = () => valeur(`SELECT id FROM adresse WHERE user_id = ${userId()} LIMIT 1`);
const ouvrirOnglet = async (page) => { await page.goto('/user/mon-compte#adresses'); await expect(page.locator('#tab-adresses')).toBeVisible(); };
const URL_ONGLET = /\/user\/mon-compte#adresses$/;

async function remplir(page, valeurs) {
  for (const [champ, v] of Object.entries(valeurs)) await page.fill(`#tab-adresses input[name="adresse[${champ}]"]`, v);
}
const champsDuFormulaire = (page, portee) => page.locator(`${portee} form[name="adresse"] [name^="adresse["]`).evaluateAll((els) => els.map((e) => e.name).sort());

test('l\'onglet contient la vue complète : liste, formulaire d\'ajout, actions ; plus de simple lien', async ({ page }) => {
  await ouvrirOnglet(page);
  const onglet = page.locator('#tab-adresses');
  await expect(onglet.locator('#adresses-list')).toContainText('12 rue des Tests');
  await expect(onglet.locator('form[name="adresse"]')).toHaveCount(1);
  await expect(onglet).toContainText('Ajouter une adresse');
  for (const champ of ['nom', 'rue', 'complement', 'codePostal', 'ville', 'telephone']) {
    await expect(onglet.locator(`[name="adresse[${champ}]"]`)).toBeVisible();
  }
  await expect(onglet.locator('a:has-text("Modifier")')).toHaveCount(1);
  await expect(page.locator('#lien-gerer-adresses')).toHaveCount(0);
});

test('une seule source : /compte/adresses affiche les mêmes champs et la même liste que l\'onglet', async ({ page }) => {
  await ouvrirOnglet(page);
  const dansOnglet = await champsDuFormulaire(page, '#tab-adresses');
  await page.goto('/compte/adresses');
  const surPage = await champsDuFormulaire(page, 'body');
  expect(surPage).toEqual(dansOnglet);
  expect(surPage.length).toBeGreaterThan(6);
  await expect(page.locator('#adresses-list')).toContainText('12 rue des Tests');
});

test('ajouter une adresse dans l\'onglet : on reste sur le tableau de bord, avec la nouvelle adresse', async ({ page }) => {
  await ouvrirOnglet(page);
  const avant = nb();
  await remplir(page, { nom: 'Bureau', rue: '5 avenue des Tests', codePostal: '34070', ville: 'Montpellier' });
  await page.locator('#tab-adresses form[name="adresse"] button[type="submit"]').click();
  await page.waitForLoadState('networkidle');

  expect(nb()).toBe(avant + 1);
  await expect(page).toHaveURL(URL_ONGLET);
  await expect(page.locator('#tab-adresses')).toBeVisible();
  await expect(page.locator('#tab-adresses #adresses-list')).toContainText('5 avenue des Tests');
  await expect(page.locator('[role="alert"]')).toContainText('Adresse ajoutée');
});

test('erreur de validation dans l\'onglet : message dans l\'onglet, rien d\'enregistré, saisie conservée', async ({ page }) => {
  await ouvrirOnglet(page);
  const avant = nb();
  await remplir(page, { nom: 'Corse', rue: '1 rue du Port', codePostal: '20200', ville: 'Bastia' });
  await page.locator('#tab-adresses form[name="adresse"] button[type="submit"]').click();
  await page.waitForLoadState('networkidle');

  expect(nb()).toBe(avant);
  await expect(page).toHaveURL(URL_ONGLET);
  await expect(page.locator('#tab-adresses')).toBeVisible();
  await expect(page.locator('#tab-adresses')).toContainText('Cette adresse n\'est pas desservie');
  await expect(page.locator('#tab-adresses input[name="adresse[rue]"]')).toHaveValue('1 rue du Port');
});

test('modifier dans l\'onglet : formulaire prérempli, enregistrement, retour dans l\'onglet', async ({ page }) => {
  await ouvrirOnglet(page);
  await page.locator('#tab-adresses a:has-text("Modifier")').click();
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveURL(URL_ONGLET);
  await expect(page.locator('#tab-adresses')).toBeVisible();
  await expect(page.locator('#tab-adresses')).toContainText('Modifier l\'adresse');
  await expect(page.locator('#tab-adresses input[name="adresse[rue]"]')).toHaveValue('12 rue des Tests');

  await page.fill('#tab-adresses input[name="adresse[rue]"]', '99 rue Modifiée');
  await page.locator('#tab-adresses form[name="adresse"] button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  expect(valeur(`SELECT rue FROM adresse WHERE id = ${idAdresse()}`)).toBe('99 rue Modifiée');
  await expect(page).toHaveURL(URL_ONGLET);
  await expect(page.locator('#tab-adresses #adresses-list')).toContainText('99 rue Modifiée');
});

test('adresse par défaut puis suppression dans l\'onglet ; l\'adresse par défaut n\'a pas de bouton de suppression', async ({ page }) => {
  executerSql(`INSERT INTO adresse (user_id, nom, rue, code_postal, ville, pays, par_defaut, created_at) VALUES (${userId()}, 'Autre', '3 rue Seconde', '34000', 'Montpellier', 'France', 0, NOW())`);
  page.on('dialog', (d) => d.accept());
  await ouvrirOnglet(page);
  const idAutre = valeur(`SELECT id FROM adresse WHERE nom = 'Autre' AND user_id = ${userId()}`);

  await page.locator(`#tab-adresses form[action*="/${idAutre}/definir-par-defaut"] button`).click();
  await page.waitForLoadState('networkidle');
  expect(valeur(`SELECT par_defaut FROM adresse WHERE id = ${idAutre}`)).toBe('1');
  await expect(page).toHaveURL(URL_ONGLET);

  await ouvrirOnglet(page);
  const ancienne = valeur(`SELECT id FROM adresse WHERE nom = 'Domicile' AND user_id = ${userId()}`);
  await page.locator(`#tab-adresses form[action*="/${ancienne}/supprimer"] button`).click();
  await page.waitForLoadState('networkidle');
  expect(valeur(`SELECT COUNT(*) FROM adresse WHERE id = ${ancienne}`)).toBe('0');
  await expect(page).toHaveURL(URL_ONGLET);

  await ouvrirOnglet(page);
  await expect(page.locator('#tab-adresses form[action*="/supprimer"]')).toHaveCount(0);
});

test('contrôles identiques : jeton CSRF invalide refusé, adresse d\'un autre client introuvable, visiteur renvoyé à la connexion', async ({ page, browser }) => {
  creerAutreClient();
  executerSql("INSERT INTO adresse (user_id, nom, rue, code_postal, ville, pays, par_defaut, created_at) VALUES ((SELECT id FROM user WHERE email = 'autre@perline.test'), 'Chez autre', '1 rue Privée', '34000', 'Montpellier', 'France', 1, NOW())");
  const idAutre = valeur("SELECT id FROM adresse WHERE nom = 'Chez autre'");
  const mienne = idAdresse();

  const r1 = await page.request.post(`/compte/adresses/${mienne}/supprimer?depuis=compte`, { form: { _token: 'faux' }, maxRedirects: 0 });
  expect([302, 303]).toContain(r1.status());
  expect(valeur(`SELECT COUNT(*) FROM adresse WHERE id = ${mienne}`)).toBe('1');

  expect((await page.request.get(`/compte/adresses/${idAutre}/modifier?depuis=compte`)).status()).toBe(404);
  expect((await page.request.post(`/compte/adresses/${idAutre}/supprimer?depuis=compte`, { form: { _token: 'x' } })).status()).toBe(404);
  expect(valeur(`SELECT COUNT(*) FROM adresse WHERE id = ${idAutre}`)).toBe('1');

  const visiteur = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: process.env.E2E_BASE_URL });
  const r2 = await visiteur.request.get('/compte/adresses?depuis=compte', { maxRedirects: 0 });
  expect([302, 401, 403]).toContain(r2.status());
  expect(r2.headers()['location'] ?? '').toContain('/login');
  await visiteur.close();
});

test('l\'ancienne création d\'adresse par le tableau de bord n\'existe plus : un POST sur /user/mon-compte ne crée rien', async ({ page }) => {
  const avant = valeur('SELECT COUNT(*) FROM adresse');
  const reponse = await page.request.post('/user/mon-compte', { form: { 'adresse[rue]': 'x', 'adresse[ville]': 'y' }, maxRedirects: 0 });
  expect(reponse.status()).toBeGreaterThanOrEqual(400);
  expect(valeur('SELECT COUNT(*) FROM adresse')).toBe(avant);
});
