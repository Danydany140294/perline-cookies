// Chapitre 1.5, bloc 2 : détail de commande et accès par changement d'identifiant dans l'URL sur TOUTES les routes du compte qui prennent un id.
import { test, request } from '@playwright/test';
import { reinitialiser, creerAutreClient, COOKIE } from '../lib/etat.js';
import { lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(() => reinitialiser());

const idAdresse = () => valeur("SELECT id FROM adresse WHERE user_id = (SELECT id FROM user WHERE email = 'e2e@perline.test') LIMIT 1");

async function commandePayee(page, quantite = 2) {
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page, 'livraison');
  expect(c).not.toBeNull();
  await poster(page.request, paiement(c));

  return c;
}

/** Contexte (navigateur) d'un autre client connecté. */
async function autreClient(browser) {
  creerAutreClient();
  const contexte = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  const page = await contexte.newPage();
  await page.goto('/login');
  await page.fill('input[name="email"]', 'autre@perline.test');
  await page.fill('input[name="password"]', process.env.E2E_USER_MDP);
  await page.press('input[name="password"]', 'Enter');
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });

  return { contexte, page };
}

test('détail de commande : articles, adresse, frais, TVA, facture, suivi — pour le propriétaire', async ({ page }) => {
  const c = await commandePayee(page);
  const idFacture = valeur(`SELECT id FROM facture WHERE commande_id = ${c.id}`);

  await page.goto(`/compte/commandes/${c.id}`);

  await expect(page.locator('h1')).toContainText(c.numero ?? '');
  await expect(page.locator('#commande-statut')).toHaveText(/Payée/);
  await expect(page.locator('#commande-articles')).toContainText('Pistache');
  const totaux = await page.locator('#commande-totaux').textContent();
  expect(totaux).toContain('Total HT');
  expect(totaux).toContain('TVA 5,50 %');
  expect(totaux).toContain('Total TTC');
  await expect(page.locator('#commande-livraison')).toContainText('12 rue des Tests');
  await expect(page.locator(`#commande-documents a[href="/compte/factures/${idFacture}/telecharger"]`)).toContainText('Télécharger la facture');

  // Suivi renseigné : numéro et lien
  const { executerSql } = await import('../lib/db.js');
  executerSql(`UPDATE commande SET statut = 'expediee', numero_suivi = 'AB123456789', transporteur = 'Colissimo', date_expedition = NOW() WHERE id = ${c.id}`);
  await page.goto(`/compte/commandes/${c.id}`);
  await expect(page.locator('#commande-suivi')).toContainText('AB123456789');
  await expect(page.locator('#commande-suivi a[href*="laposte.fr"]')).toHaveCount(1);

  // Depuis « Mes commandes », le bouton mène au détail
  await page.goto('/user/mon-compte');
  await expect(page.locator(`#tab-commandes a[href="/compte/commandes/${c.id}"]`)).toHaveCount(1);
});

test('détail de commande : 404 pour un autre compte, pour un visiteur et pour une commande inexistante', async ({ page, browser }) => {
  const c = await commandePayee(page);
  const { contexte, page: autre } = await autreClient(browser);

  expect((await autre.request.get(`/compte/commandes/${c.id}`)).status()).toBe(404);
  expect((await autre.request.get('/compte/commandes/99999999')).status()).toBe(404);
  await contexte.close();

  const visiteur = await request.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  expect((await visiteur.get(`/compte/commandes/${c.id}`, { maxRedirects: 0 })).status()).toBe(404);
  await visiteur.dispose();
});

test('adresses : un autre compte reçoit 404 sur modifier / supprimer / définir par défaut, rien ne change', async ({ page, browser }) => {
  const id = idAdresse();
  const avant = lignes(`SELECT rue, par_defaut FROM adresse WHERE id = ${id}`)[0];
  const { contexte, page: autre } = await autreClient(browser);

  expect((await autre.request.get(`/compte/adresses/${id}/modifier`)).status()).toBe(404);
  expect((await autre.request.post(`/compte/adresses/${id}/modifier`, { form: { 'adresse[rue]': 'Pirate' } })).status()).toBe(404);
  expect((await autre.request.post(`/compte/adresses/${id}/supprimer`, { form: { _token: 'x' } })).status()).toBe(404);
  expect((await autre.request.post(`/compte/adresses/${id}/definir-par-defaut`, { form: { _token: 'x' } })).status()).toBe(404);
  await contexte.close();

  expect(lignes(`SELECT rue, par_defaut FROM adresse WHERE id = ${id}`)[0]).toEqual(avant);
});

test('adresses : visiteur renvoyé vers la connexion, suppression et définition par défaut refusées en GET ou sans jeton CSRF', async ({ page }) => {
  const id = idAdresse();
  const visiteur = await request.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  for (const url of [`/compte/adresses/${id}/modifier`, `/compte/adresses/${id}/supprimer`, `/compte/adresses/${id}/definir-par-defaut`]) {
    const r = await visiteur.get(url, { maxRedirects: 0 });
    expect([301, 302, 401, 403, 405], url).toContain(r.status());
  }
  await visiteur.dispose();

  // Propriétaire : GET interdit (méthode), POST sans jeton refusé
  expect((await page.request.get(`/compte/adresses/${id}/supprimer`)).status()).toBe(405);
  expect((await page.request.get(`/compte/adresses/${id}/definir-par-defaut`)).status()).toBe(405);
  await page.request.post(`/compte/adresses/${id}/supprimer`, { form: {}, maxRedirects: 0 });
  await page.request.post(`/compte/adresses/${id}/supprimer`, { form: { _token: 'faux' }, maxRedirects: 0 });
  expect(valeur(`SELECT COUNT(*) FROM adresse WHERE id = ${id}`)).toBe('1');

  // Avec le vrai jeton (formulaire de la page, adresse non par défaut), la suppression fonctionne
  const { executerSql } = await import('../lib/db.js');
  executerSql(`UPDATE adresse SET par_defaut = 0 WHERE id = ${id}`);
  page.on('dialog', (d) => d.accept());
  await page.goto('/compte/adresses');
  await page.locator(`form[action$="/compte/adresses/${id}/supprimer"] button`).click();
  await page.waitForLoadState('networkidle');
  expect(valeur(`SELECT COUNT(*) FROM adresse WHERE id = ${id}`)).toBe('0');});

test('factures : un autre compte reçoit 404, un visiteur est renvoyé vers la connexion', async ({ page, browser }) => {
  const c = await commandePayee(page);
  const idFacture = valeur(`SELECT id FROM facture WHERE commande_id = ${c.id}`);
  const { contexte, page: autre } = await autreClient(browser);

  expect((await autre.request.get(`/compte/factures/${idFacture}/telecharger`)).status()).toBe(404);
  expect((await autre.request.get('/compte/factures/99999999/telecharger')).status()).toBe(404);
  await contexte.close();

  const visiteur = await request.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  expect([301, 302, 401, 403]).toContain((await visiteur.get(`/compte/factures/${idFacture}/telecharger`, { maxRedirects: 0 })).status());
  await visiteur.dispose();
});
