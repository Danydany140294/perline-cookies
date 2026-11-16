// Bloc 2 du chapitre 1.3 : zones desservies, frais en configuration, modes proposés, CGV alignées.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE, BOX } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import { expect, ajouterCookie, ajouterBox, cliquerSurPayer, colonneCommande, bandeau } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

const userId = "(SELECT id FROM user WHERE email = 'e2e@perline.test')";

function ajouterAdresse(nom, rue, codePostal, ville, pays = 'France', parDefaut = 0) {
  executerSql(`INSERT INTO adresse (user_id, nom, rue, code_postal, ville, pays, par_defaut, created_at) VALUES (${userId}, '${nom}', '${rue}', '${codePostal}', '${ville}', '${pays}', ${parDefaut}, NOW())`);
  return valeur(`SELECT id FROM adresse WHERE nom = '${nom}' ORDER BY id DESC LIMIT 1`);
}

test('adresses hors zone (Corse, outre-mer, étranger) : non sélectionnables, raison affichée, paiement bloqué', async ({ page }) => {
  executerSql(`UPDATE adresse SET par_defaut = 0 WHERE user_id = ${userId}`);
  executerSql(`DELETE FROM adresse WHERE user_id = ${userId}`);
  ajouterAdresse('Corse', '1 rue du Port', '20200', 'Bastia', 'France', 1);
  ajouterAdresse('Antilles', '2 rue du Fort', '97100', 'Basse-Terre');
  ajouterAdresse('Bruxelles', '3 rue Royale', '1000', 'Bruxelles', 'Belgique');
  await ajouterCookie(page.request, COOKIE.pistache, 1);

  await page.goto('/paiement');

  await expect(page.locator('.adresse-card.hors-zone')).toHaveCount(3);
  await expect(page.locator('.adresse-hors-zone').nth(0)).toContainText('Cette adresse n\'est pas desservie : nous ne livrons pas encore la Corse');
  await expect(page.locator('.adresse-hors-zone').nth(1)).toContainText('outre-mer');
  await expect(page.locator('.adresse-hors-zone').nth(2)).toContainText('France métropolitaine');
  await expect(page.getByText('Aucune de vos adresses n\'est desservie')).toBeVisible();
  await expect(page.locator('#checkout-btn')).toBeDisabled();

  // En retrait, aucune adresse n'est nécessaire : le bouton redevient actif
  await page.check('input[name="mode"][value="retrait"]');
  await expect(page.locator('#checkout-btn')).toBeEnabled();
});

test('le serveur refuse une adresse hors zone (même forgée), et accepte une adresse desservie', async ({ page }) => {
  const idCorse = ajouterAdresse('Corse2', '1 rue du Port', '20000', 'Ajaccio');
  const idOk = ajouterAdresse('Lyon', '5 place Bellecour', '69002', 'Lyon');

  const refus = await page.request.post('/paiement/choisir-adresse', { data: { adresse_id: Number(idCorse) } });
  expect(refus.status()).toBe(400);
  expect((await refus.json()).error).toContain('nous ne livrons pas encore la Corse');

  const ok = await page.request.post('/paiement/choisir-adresse', { data: { adresse_id: Number(idOk) } });
  expect(ok.status()).toBe(200);
});

test('une adresse desservie reste utilisable à côté d\'une adresse hors zone', async ({ page }) => {
  executerSql(`UPDATE adresse SET par_defaut = 0 WHERE user_id = ${userId}`);
  const idCorse = ajouterAdresse('Corse3', '1 rue du Port', '20000', 'Ajaccio', 'France', 1);
  await ajouterCookie(page.request, COOKIE.pistache, 1);

  await page.goto('/paiement');
  await expect(page.locator(`.adresse-card[data-id="${idCorse}"].hors-zone`)).toHaveCount(1);
  // L'adresse du départ (Montpellier) est desservie : on la choisit
  await page.locator('.adresse-card:not(.hors-zone)').first().click();
  await page.waitForLoadState('networkidle');
  const c = await cliquerSurPayer(page, 'livraison', { naviguer: false });

  expect(c).not.toBeNull();
  expect(colonneCommande(c.id, 'livraison_ville')).toBe('Montpellier');
});

test('formulaire d\'adresse : un code postal de Corse est refusé avec un message clair, pays figé sur France', async ({ page }) => {
  const id = valeur(`SELECT id FROM adresse WHERE user_id = ${userId} LIMIT 1`);
  await page.goto(`/compte/adresses/${id}/modifier`);
  await page.fill('input[name="adresse[codePostal]"]', '20200');
  await page.locator('form[name="adresse"] button[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');

  await expect(page.locator('body')).toContainText('Cette adresse n\'est pas desservie : nous ne livrons pas encore la Corse');
  expect(valeur(`SELECT code_postal FROM adresse WHERE id = ${id}`)).toBe('34000');
  const options = await page.locator('select[name="adresse[pays]"] option').allTextContents();
  expect(options).toEqual(['France']);
});

test('frais : forfait 4,50 € sous 50 €, offerts dès 50 €, détail Colissimo avant paiement, frais copiés sur la commande', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 2); // 2 cookies : sous le seuil
  await page.goto('/paiement');
  await expect(page.locator('#recap-frais-label')).toContainText('Livraison (Colissimo)');
  await expect(page.locator('#recap-frais')).toContainText('4,50 €');
  await expect(page.locator('#recap-astuce')).toContainText('pour la livraison offerte');
  const sous = await cliquerSurPayer(page, 'livraison');
  expect(Number(colonneCommande(sous.id, 'frais_livraison'))).toBeCloseTo(4.5, 2);

  reinitialiser();
  await ajouterBox(page.request, BOX.prestige, 1);   // 34,90 €
  await ajouterBox(page.request, BOX.decouverte, 1); // + 19,90 € = 54,80 € : au-dessus du seuil
  await page.goto('/paiement');
  await expect(page.locator('#recap-frais')).toContainText('Offerts');
  await expect(page.locator('#recap-astuce')).toHaveCount(0);
  const offerte = await cliquerSurPayer(page, 'livraison');
  expect(Number(colonneCommande(offerte.id, 'frais_livraison'))).toBe(0);
  expect(offerte.total).toBeCloseTo(54.8, 2);
});

test('réglage « retrait désactivé » : le retrait n\'est ni affiché ni accepté (serveur de test configuré sans retrait)', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const serveurSansRetrait = process.env.E2E_BASE_URL2;

  await page.goto(`${serveurSansRetrait}/paiement`);
  await expect(page.locator('input[name="mode"][value="livraison"]')).toBeChecked();
  await expect(page.locator('input[name="mode"][value="retrait"]')).toHaveCount(0);

  const refus = await page.request.post(`${serveurSansRetrait}/paiement/create-checkout-session`, { data: { mode: 'retrait' } });
  expect(refus.status()).toBe(400);
  expect((await refus.json()).error).toContain('mode de réception n\'est pas proposé');
});

test('les CGV reprennent la configuration : forfait 4,50 €, offerte dès 50 €, Colissimo, hors Corse (plus de Chronopost, de poids ni de 40 €)', async ({ page }) => {
  await page.goto('/conditions-generales-de-vente');
  const texte = await page.locator('body').innerText();

  expect(texte).toContain('forfait de 4,50 €');
  expect(texte).toContain('offerte à partir de 50 € d\'achat');
  expect(texte).toContain('hors Corse');
  expect(texte).toContain('Colissimo');
  expect(texte).not.toContain('Chronopost');
  expect(texte).not.toMatch(/en fonction du poids/);
  expect(texte).not.toContain('40 €');
});
