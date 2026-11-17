// Marque blanche : écran « Paramètres de la boutique » (administrateur seulement), valeurs par défaut de boutique.yaml, journal.
import { test } from '@playwright/test';
import { reinitialiser, creerAutreClient, COOKIE } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer, emails, viderEmails } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

const MDP = process.env.E2E_USER_MDP;
async function connecter(page, email = 'admin@creaself.com', mdp = 'admin1234') {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', mdp);
  await page.press('input[name="password"]', 'Enter');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
}
const champ = (page, nom) => page.locator(`[name="parametres_boutique[${nom}]"], [name="${nom}"]`).first();
async function remplir(page, valeurs) {
  for (const [nom, v] of Object.entries(valeurs)) {
    const el = page.locator(`#parametres_boutique_${nom}, #${nom}`).first();
    const type = await el.getAttribute('type');
    if (type === 'checkbox') await el.setChecked(Boolean(v), { force: true });
    else await el.fill(String(v));
  }
}
const enregistrer = async (page) => { await page.click('#enregistrer-parametres'); await page.waitForLoadState('networkidle'); };
const texteSite = async (page, url) => { await page.goto(url); return page.locator('body').evaluate((n) => n.textContent); };

test('l\'administrateur change l\'identité et la livraison : le site, les frais, les alertes et le journal suivent ; retour au défaut = ligne supprimée', async ({ page, browser }) => {
  await connecter(page);
  await page.goto('/admin/parametres');
  await expect(page.locator('#page-parametres')).toBeVisible();
  // Aucun secret n'est saisissable ici
  expect(await page.locator('#page-parametres input, #page-parametres textarea').evaluateAll((els) => els.map((e) => e.name).join(' '))).not.toMatch(/stripe|secret|dsn|password|token_api/i);

  await remplir(page, {
    boutique__nom_marque: 'Maison Test',
    boutique__raison_sociale: 'Maison Test SARL',
    boutique__email_alertes: 'alertes@exemple.invalid',
    livraison__frais_forfait_centimes: '6,90',
    livraison__seuil_gratuite_centimes: '40',
  });
  await enregistrer(page);
  await expect(page.locator('.alert-success')).toContainText('Paramètres enregistrés');

  // Le site reflète la nouvelle identité (titre, mentions légales) et la marque d'origine a disparu des pages
  await page.goto('/produits');
  expect(await page.title()).toContain('Maison Test');
  const mentions = await texteSite(page, '/mentions-legales');
  expect(mentions).toContain('Maison Test SARL');
  expect(mentions).not.toContain('Perline Cookies SAS (exemple)');
  expect(await texteSite(page, '/admin')).toContain('Maison Test'); // titre du tableau de bord

  // Les frais de livraison nouveaux s'appliquent (6,90 € sous 40 €), l'alerte part à la nouvelle adresse
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const client = await contexte.newPage();
  await ajouterCookie(client.request, COOKIE.pistache, 1);
  const c = await cliquerSurPayer(client, 'livraison');
  await poster(client.request, paiement(c));
  await contexte.close();
  expect(Number(valeur(`SELECT frais_livraison FROM commande WHERE id = ${c.id}`))).toBeCloseTo(6.9, 2);
  await expect.poll(async () => (await emails({ avecAlertes: true })).filter((m) => m.Subject.includes('[Alerte]') && m.To[0].Address === 'alertes@exemple.invalid').length).toBeGreaterThan(0);

  // Journal : qui, quoi, avant / après
  const [auteur, action, entite, avant, apres] = lignes("SELECT auteur, action, entite, avant, apres FROM journal_admin WHERE action = 'parametres' ORDER BY id DESC LIMIT 1")[0];
  expect(auteur).toBe('admin@creaself.com');
  expect(entite).toBe('ParametresBoutique');
  expect(JSON.parse(avant)['Nom de la marque']).toBe('Perline Cookies');
  expect(JSON.parse(apres)['Nom de la marque']).toBe('Maison Test');

  // Retour à la valeur par défaut du fichier de configuration : la ligne en base disparaît
  expect(valeur("SELECT COUNT(*) FROM parametre_boutique WHERE cle = 'boutique.nom_marque'")).toBe('1');
  await page.goto('/admin/parametres');
  await remplir(page, { boutique__nom_marque: 'Perline Cookies', boutique__raison_sociale: 'Perline Cookies SAS (exemple)' });
  await enregistrer(page);
  expect(valeur("SELECT COUNT(*) FROM parametre_boutique WHERE cle = 'boutique.nom_marque'")).toBe('0');
});

test('validation : e-mail invalide, montant négatif, jours impossibles, aucun mode de réception : rien n\'est enregistré', async ({ page }) => {
  await connecter(page);
  await page.goto('/admin/parametres');
  await remplir(page, { boutique__email_contact: 'pas-un-email', livraison__frais_forfait_centimes: '-3', livraison__delais__jours_expedition: '9', boutique__nom_marque: '' });
  await enregistrer(page);
  await expect(page.locator('#page-parametres')).toContainText('Adresse e-mail invalide');
  await expect(page.locator('#page-parametres')).toContainText('Ce champ est obligatoire');
  await expect(page.locator('#page-parametres')).toContainText('Jours de 1 (lundi) à 7 (dimanche)');
  expect(valeur('SELECT COUNT(*) FROM parametre_boutique')).toBe('0');

  await page.goto('/admin/parametres');
  await remplir(page, { livraison__modes__livraison: false, livraison__modes__retrait: false });
  await enregistrer(page);
  await expect(page.locator('#page-parametres')).toContainText('Au moins un mode de réception');
  expect(valeur('SELECT COUNT(*) FROM parametre_boutique')).toBe('0');
});

test('mode démo décoché dans les paramètres : le bandeau disparaît ; fonctions optionnelles activables', async ({ page }) => {
  await connecter(page);
  await page.goto('/');
  await expect(page.locator('.bandeau-demo')).toHaveCount(1);
  await page.goto('/admin/parametres');
  await remplir(page, { boutique__mode_demo: false });
  await enregistrer(page);
  await page.goto('/');
  await expect(page.locator('.bandeau-demo')).toHaveCount(0);
  expect(JSON.parse(valeur("SELECT valeur FROM parametre_boutique WHERE cle = 'boutique.mode_demo'"))).toBe(false);
});

test('le gestionnaire n\'a pas accès aux paramètres (403) et ne voit pas le menu', async ({ page }) => {
  creerAutreClient();
  executerSql("UPDATE user SET roles = '[\"ROLE_GESTIONNAIRE\"]' WHERE email = 'autre@perline.test'");
  await connecter(page, 'autre@perline.test', MDP);
  const r = await page.goto('/admin/parametres');
  expect(r.status()).toBe(403);
  await page.goto('/admin');
  await expect(page.locator('a[href*="/admin/parametres"]')).toHaveCount(0);
  const r2 = await page.request.post('/admin/parametres', { form: { 'parametres_boutique[boutique__nom_marque]': 'Pirate' } });
  expect(r2.status()).toBe(403);
  expect(valeur('SELECT COUNT(*) FROM parametre_boutique')).toBe('0');
});
