// Chapitre 1.6, bloc 1 : journal des actions, rôle gestionnaire, utilisateurs et lignes de commande en lecture seule.
import { test, request } from '@playwright/test';
import { reinitialiser, creerAutreClient, COOKIE } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(() => reinitialiser());

const MDP = process.env.E2E_USER_MDP;

async function connecter(page, email, mdp) {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', mdp);
  await page.press('input[name="password"]', 'Enter');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
}
const connecterAdmin = (page) => connecter(page, 'admin@creaself.com', 'admin1234');
const journal = () => lignes('SELECT auteur, action, entite, entite_id, avant, apres FROM journal_admin ORDER BY id')
  .map(([auteur, action, entite, id, avant, apres]) => ({ auteur, action, entite, id: Number(id), avant: JSON.parse(avant), apres: JSON.parse(apres) }));

async function enregistrer(page) {
  await page.locator('.action-saveAndReturn').first().click();
  await page.waitForLoadState('networkidle');
}

test('modifier prix, stock puis désactiver un cookie dans l\'administration : tout est journalisé', async ({ page }) => {
  await connecterAdmin(page);
  await page.goto('/admin/produit/2/edit');
  await page.fill('[name="Produit[prix]"]', '5.20');
  await page.fill('[name="Produit[stock]"]', '40');
  await enregistrer(page);

  let entrees = journal();
  expect(entrees).toHaveLength(1);
  expect(entrees[0]).toMatchObject({ auteur: 'admin@creaself.com', action: 'modification', entite: 'Produit', id: 2 });
  expect(entrees[0].apres).toMatchObject({ 'Prix': 5.2, 'Stock': 40 });
  expect(entrees[0].avant['Stock']).toBe(50);

  await page.goto('/admin/produit/2/edit');
  await page.locator('[name="Produit[isActive]"]').uncheck({ force: true });
  await enregistrer(page);
  entrees = journal();
  expect(entrees.at(-1)).toMatchObject({ action: 'desactivation', entite: 'Produit', id: 2 });
  expect(entrees.at(-1).apres).toEqual({ 'Actif': false });

  // Modification sans changement : rien dans le journal
  const avant = entrees.length;
  await page.goto('/admin/produit/2/edit');
  await enregistrer(page);
  expect(journal()).toHaveLength(avant);
});

test('une commande de client (stock retiré au paiement) n\'est pas une action d\'administration : rien dans le journal', async ({ page, browser }) => {
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const client = await contexte.newPage();
  await ajouterCookie(client.request, COOKIE.pistache, 2);
  const c = await cliquerSurPayer(client, 'livraison');
  await poster(client.request, paiement(c));
  await contexte.close();

  expect(valeur(`SELECT stock FROM produit WHERE id = ${COOKIE.pistache}`)).toBe('48');
  expect(journal()).toHaveLength(0);
});

test('gestionnaire : donné par l\'administrateur (tracé), accès aux commandes / catalogue, refusé sur utilisateurs, journal et paniers', async ({ page, browser }) => {
  creerAutreClient();
  const idAutre = valeur("SELECT id FROM user WHERE email = 'autre@perline.test'");

  // L'administrateur donne le rôle gestionnaire : formulaire sans mot de passe, rôles non modifiables
  await connecterAdmin(page);
  await page.goto(`/admin/user/${idAutre}/edit`);
  await expect(page.locator('[name="User[password]"]')).toHaveCount(0);
  await expect(page.locator('[name="User[roles]"], [name^="User[roles]"]')).toHaveCount(0);
  await page.locator('[name="User[gestionnaire]"]').check({ force: true });
  await enregistrer(page);
  expect(valeur(`SELECT roles FROM user WHERE id = ${idAutre}`)).toContain('ROLE_GESTIONNAIRE');
  const entree = journal().find((e) => e.action === 'roles');
  expect(entree).toMatchObject({ auteur: 'admin@creaself.com', entite: 'User', id: Number(idAutre) });
  expect(entree.apres['Rôles']).toContain('ROLE_GESTIONNAIRE');
  expect(valeur(`SELECT roles FROM user WHERE id = ${idAutre}`)).not.toContain('ROLE_ADMIN'); // ROLE_ADMIN : uniquement par app:promote-admin

  // Pas de création ni de suppression d'utilisateur depuis l'administration
  expect((await page.request.get('/admin/user/new')).status()).toBeGreaterThanOrEqual(400);

  // Le gestionnaire
  const contexte = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  const g = await contexte.newPage();
  await connecter(g, 'autre@perline.test', MDP);
  for (const url of ['/admin', '/admin/commande', '/admin/produit', '/admin/box', '/admin/ligne-commande']) {
    expect((await g.request.get(url)).status(), url).toBe(200);
  }
  for (const url of ['/admin/user', '/admin/journal-admin', '/admin/panier', '/admin/ligne-panier']) {
    const r = await g.request.get(url); if (r.status() !== 403) console.log('DEBUG', url, r.status(), (await r.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 600)); expect(r.status(), url).toBe(403);
  }
  // Il peut modifier le stock : tracé à son nom
  await g.goto('/admin/produit/3/edit');
  await g.fill('[name="Produit[stock]"]', '7');
  await g.locator('.action-saveAndReturn').first().click();
  await g.waitForLoadState('networkidle');
  expect(journal().at(-1)).toMatchObject({ auteur: 'autre@perline.test', action: 'modification', entite: 'Produit', id: 3 });
  // Pas de suppression pour lui
  expect((await g.request.post('/admin/produit/3/delete')).status()).toBeGreaterThanOrEqual(400);
  expect(valeur('SELECT COUNT(*) FROM produit WHERE id = 3')).toBe('1');
  await contexte.close();

  // Un client ordinaire n'a aucun accès à l'administration
  creerAutreClient; // (le client de test e2e est un simple client)
  const simple = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: '.auth/utilisateur.json' });
  expect((await simple.request.get('/admin')).status()).toBe(403);
  await simple.close();
});

test('journal : lecture seule (ni création, ni modification, ni suppression) et ligne non modifiable en base par l\'application', async ({ page }) => {
  executerSql("INSERT INTO journal_admin (date_action, auteur, action, entite, entite_id, libelle, avant, apres) VALUES (NOW(), 'test', 'modification', 'Produit', 1, 'x', '{}', '{}')");
  const id = valeur('SELECT id FROM journal_admin LIMIT 1');
  await connecterAdmin(page);

  await page.goto('/admin/journal-admin');
  await expect(page.locator('body')).toContainText('Journal des actions');
  for (const [methode, url] of [['get', '/admin/journal-admin/new'], ['get', `/admin/journal-admin/${id}/edit`], ['post', `/admin/journal-admin/${id}/delete`], ['post', '/admin/journal-admin/batch-delete']]) {
    expect((await page.request[methode](url)).status(), url).toBeGreaterThanOrEqual(400);
  }
  expect(valeur('SELECT COUNT(*) FROM journal_admin')).toBe('1');
});

test('lignes de commande : lecture seule, composition figée des boxes visible dans le détail de la commande', async ({ page, browser }) => {
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const client = await contexte.newPage();
  const { ajouterBox } = await import('../lib/aide.js');
  await ajouterBox(client.request, 1, 1); // Box Gourmande (recette fixe)
  const c = await cliquerSurPayer(client, 'livraison');
  await contexte.close();
  const idLigne = valeur(`SELECT id FROM ligne_commande WHERE commande_id = ${c.id} LIMIT 1`);

  await connecterAdmin(page);
  for (const [methode, url] of [['get', '/admin/ligne-commande/new'], ['get', `/admin/ligne-commande/${idLigne}/edit`], ['post', `/admin/ligne-commande/${idLigne}/delete`]]) {
    expect((await page.request[methode](url)).status(), url).toBeGreaterThanOrEqual(400);
  }
  await page.goto(`/admin/commande/${c.id}`);
  const texte = await page.locator('body').evaluate((n) => n.textContent);
  expect(texte).toMatch(/1 × Box Gourmande/);
  expect(texte).toMatch(/\d+ × Cookie/); // composition figée (cookies et quantités)
  expect(texte).toContain('TVA 5,50 %');
});
