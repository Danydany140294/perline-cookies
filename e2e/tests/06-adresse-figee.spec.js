// Bloc 1 du chapitre 1.3 : l'adresse de livraison est COPIÉE dans la commande ; e-mail de confirmation enrichi.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer, colonneCommande, emails, viderEmails, derniereCommande } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

const idAdresse = () => valeur("SELECT id FROM adresse WHERE user_id = (SELECT id FROM user WHERE email = 'e2e@perline.test') LIMIT 1");

async function commandeLivraison(page, quantite = 2) {
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page, 'livraison');
  expect(c).not.toBeNull();

  return c;
}

test('livraison : l\'adresse est copiée dans la commande (destinataire, rue, complément, CP, ville, pays, téléphone) avec les frais', async ({ page }) => {
  const c = await commandeLivraison(page, 1); // 1 cookie : sous le seuil de gratuité

  expect(c.mode).toBe('livraison');
  expect(colonneCommande(c.id, 'livraison_destinataire')).toContain('E2E');
  expect(colonneCommande(c.id, 'livraison_rue')).toBe('12 rue des Tests');
  expect(colonneCommande(c.id, 'livraison_complement')).toBe('Bat. B');
  expect(colonneCommande(c.id, 'livraison_code_postal')).toBe('34000');
  expect(colonneCommande(c.id, 'livraison_ville')).toBe('Montpellier');
  expect(colonneCommande(c.id, 'livraison_pays')).toBe('France');
  expect(colonneCommande(c.id, 'livraison_telephone')).toBe('06 12 34 56 78');
  expect(Number(colonneCommande(c.id, 'frais_livraison'))).toBeCloseTo(4.5, 2);
});

test('modifier puis supprimer l\'adresse du compte : la commande garde sa copie, aucune erreur', async ({ page }) => {
  const c = await commandeLivraison(page);
  const id = idAdresse();

  // Modification de l'adresse du compte
  executerSql(`UPDATE adresse SET rue = '99 avenue Changée', ville = 'Lyon', code_postal = '69001' WHERE id = ${id}`);
  expect(colonneCommande(c.id, 'livraison_rue')).toBe('12 rue des Tests');
  expect(colonneCommande(c.id, 'livraison_ville')).toBe('Montpellier');

  // Suppression par la vraie page (avant : erreur 500 « contrainte de clé étrangère »)
  // (suppression en POST avec jeton CSRF : on passe par le bouton de la page ; l'adresse doit d'abord ne plus être « par défaut »)
  executerSql(`UPDATE adresse SET par_defaut = 0 WHERE id = ${id}`);
  page.on('dialog', (d) => d.accept());
  await page.goto('/compte/adresses');
  await page.locator(`form[action$="/compte/adresses/${id}/supprimer"] button`).click();
  await page.waitForLoadState('networkidle');

  expect(valeur(`SELECT COUNT(*) FROM adresse WHERE id = ${id}`)).toBe('0');
  expect(colonneCommande(c.id, 'adresse_livraison_id')).toBeNull(); // lien mis à NULL
  expect(colonneCommande(c.id, 'livraison_rue')).toBe('12 rue des Tests'); // copie intacte
  expect(colonneCommande(c.id, 'livraison_code_postal')).toBe('34000');
  expect(derniereCommande().id).toBe(c.id);
});

test('l\'e-mail de confirmation liste les articles, les frais, le destinataire, l\'adresse et le pays', async ({ page }) => {
  const c = await commandeLivraison(page, 2);
  await poster(page.request, paiement(c));

  await expect.poll(async () => (await emails()).length).toBe(1);
  const message = (await emails())[0];
  const detail = await (await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/message/${message.ID}`)).json();
  const html = detail.HTML;

  expect(html).toContain(c.numero);
  expect(html).toContain('Cookie Pistache Cannelle');
  expect(html).toMatch(/Livraison/);
  expect(html).toContain('4,50 €'); // 2 cookies (sous le seuil) : frais de livraison détaillés
  expect(html).toContain('12 rue des Tests');
  expect(html).toContain('Bat. B');
  expect(html).toContain('34000 Montpellier');
  expect(html).toContain('France');
  expect(html).toContain('06 12 34 56 78');
  expect(html).toMatch(/E2E/);
});

test('le téléphone (facultatif) se saisit dans le formulaire d\'adresse et suit la commande', async ({ page }) => {
  const id = idAdresse();
  await page.goto(`/compte/adresses/${id}/modifier`);
  await page.fill('input[name="adresse[telephone]"]', '+33 6 98 76 54 32');
  await page.locator('form[name="adresse"] button[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');
  expect(valeur(`SELECT telephone FROM adresse WHERE id = ${id}`)).toBe('+33 6 98 76 54 32');

  const c = await commandeLivraison(page, 1);
  expect(colonneCommande(c.id, 'livraison_telephone')).toBe('+33 6 98 76 54 32');
});

test('un téléphone invalide est refusé par le formulaire', async ({ page }) => {
  const id = idAdresse();
  await page.goto(`/compte/adresses/${id}/modifier`);
  await page.fill('input[name="adresse[telephone]"]', 'pas un numéro');
  await page.locator('form[name="adresse"] button[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');

  await expect(page.locator('body')).toContainText('Numéro de téléphone français invalide');
  expect(valeur(`SELECT telephone FROM adresse WHERE id = ${id}`)).toBe('06 12 34 56 78');
});
