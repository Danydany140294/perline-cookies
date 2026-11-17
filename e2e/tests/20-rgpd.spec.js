// Chapitre 1.5, bloc 4 : RGPD — export des données et suppression du compte par anonymisation (commandes et factures conservées).
import { test, request } from '@playwright/test';
import { reinitialiser, creerAutreClient, COOKIE } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer, emails, viderEmails } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

const MDP = process.env.E2E_USER_MDP;

async function contexteAutreClient(browser) {
  creerAutreClient();
  const contexte = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  const page = await contexte.newPage();
  await page.goto('/login');
  await page.fill('input[name="email"]', 'autre@perline.test');
  await page.fill('input[name="password"]', MDP);
  await page.press('input[name="password"]', 'Enter');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });

  return { contexte, page };
}

test('export : fichier JSON avec informations, adresses et commandes du client connecté', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 2);
  const c = await cliquerSurPayer(page, 'livraison');
  await poster(page.request, paiement(c));

  const reponse = await page.request.get('/compte/mes-donnees/export');
  expect(reponse.status()).toBe(200);
  expect(reponse.headers()['content-disposition']).toContain('mes-donnees.json');
  expect(reponse.headers()['cache-control']).toContain('no-store');
  const donnees = await reponse.json();

  expect(donnees.informations.email).toBe(process.env.E2E_USER_EMAIL);
  expect(donnees.adresses[0].rue).toBe('12 rue des Tests');
  expect(donnees.commandes).toHaveLength(1);
  expect(donnees.commandes[0].numero).toBe(valeur(`SELECT numero_commande FROM commande WHERE id = ${c.id}`));
  expect(donnees.commandes[0].lignes[0].article).toContain('Pistache');
  expect(donnees.commandes[0].documents[0].numero).toMatch(/^F-\d{4}-000001$/);
  expect(donnees.commandes[0].adresse_livraison.ville).toBe('Montpellier');
  expect(JSON.stringify(donnees)).not.toContain('"password"'); // jamais le mot de passe haché

  // Un visiteur ne peut pas exporter
  const visiteur = await request.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  expect([301, 302, 401, 403]).toContain((await visiteur.get('/compte/mes-donnees/export', { maxRedirects: 0 })).status());
  await visiteur.dispose();
});

test('suppression refusée tant qu\'une commande est en cours ; le mot de passe est exigé', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const c = await cliquerSurPayer(page, 'livraison');
  await poster(page.request, paiement(c));

  await page.goto('/compte/mes-donnees');
  await expect(page.locator('#suppression-refusee')).toContainText('commande(s) en cours');
  await expect(page.locator('#bloc-suppression form')).toHaveCount(0);
  expect(valeur(`SELECT anonymise_le FROM user WHERE email = '${process.env.E2E_USER_EMAIL}'`)).toBe('NULL');
});

test('suppression : mot de passe incorrect refusé ; puis compte anonymisé, accès fermé, commande et facture conservées', async ({ browser }) => {
  const { contexte, page } = await contexteAutreClient(browser);
  executerSql("INSERT INTO adresse (user_id, nom, rue, code_postal, ville, pays, par_defaut, created_at) SELECT id, 'Maison', '5 rue Secrète', '34000', 'Montpellier', 'France', 1, NOW() FROM user WHERE email = 'autre@perline.test'");

  // Une commande payée puis livrée (conservée après la suppression)
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const c = await cliquerSurPayer(page, 'retrait');
  await poster(page.request, paiement(c));
  executerSql(`UPDATE commande SET statut = 'livree' WHERE id = ${c.id}`);
  const idUser = valeur("SELECT id FROM user WHERE email = 'autre@perline.test'");

  await page.goto('/compte/mes-donnees');
  await page.fill('[name="supprimer_compte[motDePasse]"]', 'mauvais');
  page.on('dialog', (d) => d.accept());
  await page.press('[name="supprimer_compte[motDePasse]"]', 'Enter');
  await expect(page.locator('body')).toContainText('mot de passe est incorrect');
  expect(valeur(`SELECT COUNT(*) FROM user WHERE id = ${idUser} AND anonymise_le IS NULL`)).toBe('1');

  await viderEmails();
  await page.fill('[name="supprimer_compte[motDePasse]"]', MDP);
  await page.press('[name="supprimer_compte[motDePasse]"]', 'Enter');
  await page.waitForURL((u) => u.pathname === '/');
  await expect(page.locator('body')).toContainText('compte a été supprimé');

  // Anonymisé : plus aucune donnée personnelle, adresses supprimées, accès fermé
  expect(valeur(`SELECT CONCAT(email, '|', nom, '|', prenom, '|', IFNULL(telephone, 'NULL')) FROM user WHERE id = ${idUser}`)).toBe(`anonyme-${idUser}@anonymise.invalid|Anonyme|Anonyme|NULL`);
  expect(valeur(`SELECT COUNT(*) FROM adresse WHERE user_id = ${idUser}`)).toBe('0');
  // Commande et facture conservées, toujours rattachées au compte anonymisé
  expect(valeur(`SELECT COUNT(*) FROM commande WHERE id = ${c.id} AND user_id = ${idUser}`)).toBe('1');
  expect(valeur(`SELECT COUNT(*) FROM facture WHERE commande_id = ${c.id}`)).toBe('1');
  // E-mail de confirmation de suppression à l'ancienne adresse
  await expect.poll(async () => (await emails()).some((m) => m.To.some((t) => t.Address === 'autre@perline.test') && m.Subject.includes('supprimé'))).toBe(true);

  // Plus de connexion possible (ancienne adresse, ou adresse anonymisée)
  await page.goto('/login');
  await page.fill('input[name="email"]', 'autre@perline.test');
  await page.fill('input[name="password"]', MDP);
  await page.press('input[name="password"]', 'Enter');
  await expect(page).toHaveURL(/\/login/);
  await page.goto('/user/mon-compte');
  await expect(page).toHaveURL(/\/login/);
  await contexte.close();
});
