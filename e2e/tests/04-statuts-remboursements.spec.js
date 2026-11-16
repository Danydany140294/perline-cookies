// Bloc 4 du chapitre 1.2 : statuts de commande (workflow), historique, remboursements (webhook charge.refunded),
// remise en stock décidée par l'administrateur.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, stock, cliquerSurPayer, commande as lireCommande } from '../lib/aide.js';
import { paiement, expiration, poster, remboursement } from '../lib/stripe.js';

test.beforeEach(() => reinitialiser());

function historique(commandeId) {
  return lignes(`SELECT COALESCE(ancien_statut,'-'), nouveau_statut, source, COALESCE(auteur,'-'), COALESCE(note,'') FROM commande_historique WHERE commande_id = ${commandeId} ORDER BY id`)
    .map(([ancien, nouveau, source, auteur, note]) => ({ ancien, nouveau, source, auteur, note }));
}

async function commandePayee(page, quantite = 2) {
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page);
  expect(c).not.toBeNull();
  expect((await poster(page.request, paiement(c))).corps.status).toBe('payee');

  return lireCommande(c.id);
}

test('historique : création, paiement (webhook) et expiration sont tracés avec leur source', async ({ page }) => {
  const payee = await commandePayee(page);
  expect(historique(payee.id).map((h) => `${h.ancien}>${h.nouveau}:${h.source}`)).toEqual([
    '->en_attente:client',
    'en_attente>payee:webhook_stripe',
  ]);
  expect(historique(payee.id)[0].auteur).toBe(process.env.E2E_USER_EMAIL);

  await ajouterCookie(page.request, COOKIE.peche, 1);
  const abandonnee = await cliquerSurPayer(page);
  await poster(page.request, expiration(abandonnee));
  const h = historique(abandonnee.id);
  expect(h.map((x) => `${x.ancien}>${x.nouveau}:${x.source}`)).toEqual(['->en_attente:client', 'en_attente>annulee:webhook_stripe']);
  expect(h[1].note).toContain('Session Stripe expirée');
});

test('remboursement partiel : montant enregistré, statut inchangé, note dans l\'historique, rejeu ignoré', async ({ page }) => {
  const c = await commandePayee(page);

  const r1 = await poster(page.request, remboursement(c, 500));
  expect(r1.corps.status).toBe('remboursement_partiel');
  expect(lireCommande(c.id).statut).toBe('payee');
  expect(Number(valeur(`SELECT montant_rembourse FROM commande WHERE id = ${c.id}`))).toBeCloseTo(5, 2);
  expect(historique(c.id).at(-1).note).toContain('Remboursement partiel dans Stripe : 5,00 €');

  const evenement = remboursement(c, 500);
  expect((await poster(page.request, evenement)).corps.status).toBe('deja_traitee');
  expect(historique(c.id).filter((h) => h.note.includes('Remboursement partiel'))).toHaveLength(1);
});

test('remboursement total (après un partiel) : « Remboursée », stock NON remis automatiquement', async ({ page }) => {
  const c = await commandePayee(page, 2);
  expect(stock(COOKIE.pistache)).toBe(48);
  await poster(page.request, remboursement(c, 500));

  const total = await poster(page.request, remboursement(c, Math.round(c.total * 100)));

  expect(total.corps.status).toBe('remboursee');
  expect(lireCommande(c.id).statut).toBe('remboursee');
  expect(stock(COOKIE.pistache)).toBe(48); // jamais automatique : produits alimentaires
  expect(historique(c.id).at(-1)).toMatchObject({ ancien: 'payee', nouveau: 'remboursee', source: 'webhook_stripe' });
  expect((await poster(page.request, remboursement(c, Math.round(c.total * 100)))).corps.status).toBe('deja_traitee');
});

test('remboursement d\'un paiement inconnu : aucune commande touchée', async ({ page }) => {
  const c = await commandePayee(page);
  const inconnu = { ...c, id: 987654 };

  const r = await poster(page.request, remboursement(inconnu, 1000));

  expect(r.corps.status).toBe('introuvable');
  expect(lireCommande(c.id).statut).toBe('payee');
});

test.describe('administration', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  async function connecterAdmin(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', 'admin@creaself.com');
    await page.fill('input[name="password"]', 'admin1234');
    await page.press('input[name="password"]', 'Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });
  }

  async function ouvrirEdition(page, id) {
    await page.goto(`/admin/commande/${id}/edit`);
    await expect(page.locator('select[name="Commande[statut]"]')).toBeAttached(); // peut être masqué par le composant de sélection d'EasyAdmin
  }

  async function enregistrer(page) {
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');
  }

  test('transition autorisée : enregistrée avec l\'historique (source admin) ; transition interdite : refusée', async ({ page, browser }) => {
    // La commande est créée avec l'utilisateur de test (contexte connecté), puis gérée par l'admin
    const contexteClient = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const pageClient = await contexteClient.newPage();
    const c = await commandePayee(pageClient);
    await contexteClient.close();

    await connecterAdmin(page);

    // payee -> confirmee : autorisé
    await ouvrirEdition(page, c.id);
    await page.selectOption('select[name="Commande[statut]"]', { label: 'Confirmée' }, { force: true });
    await enregistrer(page);
    expect(lireCommande(c.id).statut).toBe('confirmee');
    expect(historique(c.id).at(-1)).toMatchObject({ ancien: 'payee', nouveau: 'confirmee', source: 'admin', auteur: 'admin@creaself.com' });

    // confirmee -> expediee : interdit (il faut passer par « En préparation »). Le formulaire ne propose plus que les statuts
    // atteignables : on vérifie qu'« Expédiée » n'est pas proposé, puis on force la valeur par une requête forgée.
    await ouvrirEdition(page, c.id);
    const proposes = (await page.locator('select[name="Commande[statut]"] option').allTextContents()).map((o) => o.trim());
    expect(proposes).not.toContain('Expédiée');
    await page.locator('select[name="Commande[statut]"]').evaluate((s) => { const o = document.createElement('option'); o.value = 'expediee'; o.textContent = 'Expédiée'; s.appendChild(o); s.value = 'expediee'; });
    await enregistrer(page);
    expect(lireCommande(c.id).statut).toBe('confirmee');
    expect(historique(c.id)).toHaveLength(3); // création, paiement, confirmation : rien de plus

    // la page de détail affiche l'historique
    await page.goto(`/admin/commande/${c.id}`);
    await expect(page.locator('body')).toContainText('Payée → Confirmée');
  });

  test('annuler une commande PAYÉE : avertissement « pense à la rembourser dans Stripe » et note dans l\'historique', async ({ page, browser }) => {
    const contexteClient = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const c = await commandePayee(await contexteClient.newPage());
    await contexteClient.close();

    await connecterAdmin(page);
    await ouvrirEdition(page, c.id);
    await page.selectOption('select[name="Commande[statut]"]', { label: 'Annulée' }, { force: true });
    await enregistrer(page);

    await expect(page.locator('body')).toContainText('Cette commande est payée : pense à la rembourser dans Stripe.');
    expect(lireCommande(c.id).statut).toBe('annulee');
    expect(historique(c.id).at(-1)).toMatchObject({ ancien: 'payee', nouveau: 'annulee', source: 'admin' });
    expect(historique(c.id).at(-1).note).toContain('pense à la rembourser dans Stripe');
    expect(stock(COOKIE.pistache)).toBe(48); // annuler ne remet pas le stock : c'est la case de l'admin
  });

  test('remise en stock : à cocher par l\'admin, une seule fois, jamais automatique', async ({ page, browser }) => {
    const contexteClient = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const pageClient = await contexteClient.newPage();
    const c = await commandePayee(pageClient, 2);
    await poster(pageClient.request, remboursement(c, Math.round(c.total * 100)));
    await contexteClient.close();
    expect(stock(COOKIE.pistache)).toBe(48);
    executerSql(`UPDATE produit SET disponible = 0 WHERE id = ${COOKIE.pistache}`); // désactivé à la main par l'admin

    await connecterAdmin(page);
    await ouvrirEdition(page, c.id);
    await page.locator('input[name="Commande[stockRestitue]"]').check({ force: true });
    await enregistrer(page);

    expect(stock(COOKIE.pistache)).toBe(50);
    expect(valeur(`SELECT disponible FROM produit WHERE id = ${COOKIE.pistache}`)).toBe('0'); // la remise en stock ne réactive jamais un produit
    expect(valeur(`SELECT stock_restitue FROM commande WHERE id = ${c.id}`)).toBe('1');
    expect(historique(c.id).at(-1).note).toContain('Stock remis en vente : 2 × Cookie Pistache Cannelle');

    // Décocher n'annule rien : le stock ne bouge plus
    await ouvrirEdition(page, c.id);
    await page.locator('input[name="Commande[stockRestitue]"]').uncheck({ force: true });
    await enregistrer(page);
    expect(stock(COOKIE.pistache)).toBe(50);
    expect(valeur(`SELECT stock_restitue FROM commande WHERE id = ${c.id}`)).toBe('1');
  });

  test('remise en stock refusée pour une commande non annulée/remboursée', async ({ page, browser }) => {
    const contexteClient = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const c = await commandePayee(await contexteClient.newPage(), 2);
    await contexteClient.close();

    await connecterAdmin(page);
    await ouvrirEdition(page, c.id);
    await page.locator('input[name="Commande[stockRestitue]"]').check({ force: true });
    await enregistrer(page);

    await expect(page.locator('body')).toContainText('annulée ou remboursée');
    expect(stock(COOKIE.pistache)).toBe(48);
    expect(valeur(`SELECT stock_restitue FROM commande WHERE id = ${c.id}`)).toBe('0');
  });
});
