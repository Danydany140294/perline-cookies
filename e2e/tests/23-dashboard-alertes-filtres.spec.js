// Chapitre 1.6, bloc 3 : tableau de bord, alertes (commande payée, stock) et filtres des commandes.
import { test } from '@playwright/test';
import { reinitialiser, creerAutreClient, COOKIE } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer, emails, viderEmails } from '../lib/aide.js';
import { paiement, poster, remboursement } from '../lib/stripe.js';

test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

async function messages() {
  return Promise.all((await emails()).map(async (m) => await (await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/message/${m.ID}`)).json()));
}
const alertes = async (motif) => (await messages()).filter((m) => m.Subject.includes('[Alerte]') && m.Subject.includes(motif));

async function commandePayee(browser, quantite = 1) {
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const page = await contexte.newPage();
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page, 'livraison');
  await poster(page.request, paiement(c));
  await contexte.close();

  return c;
}
async function enAttente(browser) {
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const page = await contexte.newPage();
  await ajouterCookie(page.request, COOKIE.peche, 1);
  const c = await cliquerSurPayer(page, 'livraison');
  await contexte.close();

  return c;
}

test.describe('administration', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  async function connecter(page, email = 'admin@creaself.com', mdp = 'admin1234') {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', mdp);
    await page.press('input[name="password"]', 'Enter');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
  }
  const euros = (v) => Number(v).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/[\u202f\u00a0]/g, ' ');
  const texte = (page, sel) => page.locator(sel).evaluate((n) => n.textContent.replace(/[\u202f\u00a0]/g, ' ').replace(/\s+/g, ' ').trim());

  test('tableau de bord : commandes du jour, en attente, à préparer, CA payé HT/TTC hors annulations et remboursements', async ({ page, browser }) => {
    const a = await commandePayee(browser, 2);
    const b = await commandePayee(browser, 1);
    await enAttente(browser);
    const ttc = (id) => Number(valeur(`SELECT total_ttc FROM commande WHERE id = ${id}`));
    const ht = (id) => Number(valeur(`SELECT total_ht FROM commande WHERE id = ${id}`));

    await connecter(page);
    await page.goto('/admin');
    await expect(page.locator('h1').first()).toContainText('Perline Cookies');
    expect((await page.locator('body').evaluate((n) => n.textContent)).replace(/@creaself\.com/gi, '')).not.toMatch(/CREASELF/i); // (hors adresse e-mail du compte admin)
    await expect(page.locator('#stat-commandes-jour')).toHaveText('2');
    await expect(page.locator('#stat-en-attente')).toHaveText('1');
    await expect(page.locator('#stat-a-preparer')).toHaveText('2');
    expect(await texte(page, '#ca-jour-ttc')).toContain(euros(ttc(a.id) + ttc(b.id)));
    expect(await texte(page, '#ca-jour-ht')).toContain(euros(ht(a.id) + ht(b.id)));
    expect(await texte(page, '#ca-mois-ttc')).toContain(euros(ttc(a.id) + ttc(b.id)));
    await expect(page.locator('#bloc-dernieres-commandes')).toContainText(valeur(`SELECT numero_commande FROM commande WHERE id = ${b.id}`));
    await expect(page.locator('#bloc-utilisateurs')).toHaveCount(1);

    // Annulée et remboursée : hors chiffre d'affaires
    executerSql(`UPDATE commande SET statut = 'annulee' WHERE id = ${a.id}`);
    await page.goto('/admin');
    expect(await texte(page, '#ca-jour-ttc')).toContain(euros(ttc(b.id)));
    await expect(page.locator('#stat-commandes-jour')).toHaveText('1');

    // Remboursement partiel de 5 € : le CA baisse de 5 € TTC
    await poster(page.request, remboursement(b, 500));
    await page.goto('/admin');
    expect(await texte(page, '#ca-jour-ttc')).toContain(euros(ttc(b.id) - 5));
    expect(await texte(page, '#ca-jour-ht')).toContain(euros(ht(b.id) * (ttc(b.id) - 5) / ttc(b.id)));
  });

  test('le gestionnaire voit le tableau de bord sans les statistiques d\'utilisateurs', async ({ page, browser }) => {
    creerAutreClient();
    const idAutre = valeur("SELECT id FROM user WHERE email = 'autre@perline.test'");
    executerSql(`UPDATE user SET roles = '["ROLE_GESTIONNAIRE"]' WHERE id = ${idAutre}`);
    await connecter(page, 'autre@perline.test', process.env.E2E_USER_MDP);
    await page.goto('/admin');
    await expect(page.locator('#bloc-alertes')).toHaveCount(1);
    await expect(page.locator('#bloc-utilisateurs')).toHaveCount(0);
  });

  test('alerte de stock : un seul e-mail au franchissement du seuil, de nouveau après remontée ; seuil propre au produit ; dashboard', async ({ page, browser }) => {
    await connecter(page);
    const stock = async (id, valeurStock) => {
      await page.goto(`/admin/produit/${id}/edit`);
      await page.fill('[name="Produit[stock]"]', String(valeurStock));
      await page.locator('.action-saveAndReturn').first().click();
      await page.waitForLoadState('networkidle');
    };

    await stock(COOKIE.pistache, 5); // seuil global 5 : « sous » le seuil = 4 et moins ; 5 ne déclenche rien
    expect(await alertes('Pistache')).toHaveLength(0);
    await stock(COOKIE.pistache, 4);
    await expect.poll(async () => (await alertes('Pistache')).length).toBe(1);
    const mail = (await alertes('Pistache'))[0];
    expect(mail.To[0].Address).toBe('contact@exemple.invalid'); // email_alertes vide : e-mail de contact
    expect(mail.Text).toContain('Stock actuel : 4');
    expect(mail.Text).toContain("Seuil d'alerte : 5");

    await stock(COOKIE.pistache, 3); // déjà sous le seuil : pas de second e-mail
    await stock(COOKIE.pistache, 0);
    expect((await alertes('Pistache')).length).toBe(1);
    expect(valeur(`SELECT alerte_stock_envoyee FROM produit WHERE id = ${COOKIE.pistache}`)).toBe('1');

    // Le tableau de bord l'affiche (rupture)
    await page.goto('/admin');
    await expect(page.locator('.alerte-rupture')).toContainText('Pistache');

    await stock(COOKIE.pistache, 20); // remonté : drapeau remis à zéro
    expect(valeur(`SELECT alerte_stock_envoyee FROM produit WHERE id = ${COOKIE.pistache}`)).toBe('0');
    await stock(COOKIE.pistache, 2); // nouvelle baisse : nouvelle alerte
    await expect.poll(async () => (await alertes('Pistache')).length).toBe(2);

    // Seuil propre au produit (2) : stock 3 ne déclenche rien, stock 1 oui
    await page.goto(`/admin/produit/${COOKIE.noisette}/edit`);
    await page.fill('[name="Produit[seuilAlerte]"]', '2');
    await page.fill('[name="Produit[stock]"]', '3');
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');
    expect(await alertes('Noisette')).toHaveLength(0);
    await stock(COOKIE.noisette, 1);
    await expect.poll(async () => (await alertes('Noisette')).length).toBe(1);
  });

  test('alerte « commande payée » à l\'administration, une seule fois ; stock retiré par un paiement qui franchit le seuil : alerte unique', async ({ browser }) => {
    executerSql(`UPDATE produit SET stock = 6 WHERE id = ${COOKIE.pistache}`);
    const c = await commandePayee(browser, 2); // 6 -> 4 : sous le seuil de 5

    await expect.poll(async () => (await alertes('Commande payée')).length).toBe(1);
    const mail = (await alertes('Commande payée'))[0];
    expect(mail.Subject).toContain(valeur(`SELECT numero_commande FROM commande WHERE id = ${c.id}`));
    expect(mail.Text).toContain('Total TTC');
    await expect.poll(async () => (await alertes('Stock faible')).length).toBe(1);

    // Rejeu du webhook : pas de seconde alerte
    const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    await poster(contexte.request, paiement(c));
    await contexte.close();
    expect((await alertes('Commande payée')).length).toBe(1);
  });

  test('filtres des commandes : statut, date, client (nom ou e-mail), numéro', async ({ page, browser }) => {
    const a = await commandePayee(browser, 1);
    const b = await enAttente(browser); // en attente
    const numA = valeur(`SELECT numero_commande FROM commande WHERE id = ${a.id}`);
    const numB = valeur(`SELECT numero_commande FROM commande WHERE id = ${b.id}`);
    // Une commande d'un autre client (copie SQL de la commande A, autre numéro)
    creerAutreClient();
    executerSql(`INSERT INTO commande (user_id, numero_commande, date_commande, statut, total_ttc, mode_livraison, frais_livraison, montant_rembourse, stock_retire, stock_restitue)
      SELECT (SELECT id FROM user WHERE email = 'autre@perline.test'), 'PER-AUTRE-0001', NOW(), 'livree', 10, 'retrait', 0, 0, 0, 0 FROM DUAL`);

    await connecter(page);
    const liste = async (parametres) => { await page.goto(`/admin/commande?crudAction=index&${parametres}`); return page.locator('body').evaluate((n) => n.textContent); };

    let t = await liste('filters[statut][comparison]==&filters[statut][value]=payee');
    expect(t).toContain(numA); expect(t).not.toContain(numB); expect(t).not.toContain('PER-AUTRE-0001');

    t = await liste('filters[statut][comparison]==&filters[statut][value]=en_attente');
    expect(t).toContain(numB); expect(t).not.toContain(numA);

    t = await liste('filters[user][comparison]=like&filters[user][value]=Autre');
    expect(t).toContain('PER-AUTRE-0001'); expect(t).not.toContain(numA);
    t = await liste(`filters[user][comparison]=like&filters[user][value]=${encodeURIComponent('e2e@perline')}`);
    expect(t).toContain(numA); expect(t).toContain(numB); expect(t).not.toContain('PER-AUTRE-0001');

    t = await liste(`filters[numeroCommande][comparison]=like&filters[numeroCommande][value]=${encodeURIComponent('AUTRE-0001')}`);
    expect(t).toContain('PER-AUTRE-0001'); expect(t).not.toContain(numA);

    const demain = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
    t = await liste(`filters[dateCommande][comparison]=%3E%3D&filters[dateCommande][value]=${demain}T00:00:00`);
    expect(t).not.toContain(numA); expect(t).not.toContain('PER-AUTRE-0001');
    const hier = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);
    t = await liste(`filters[dateCommande][comparison]=%3E%3D&filters[dateCommande][value]=${hier}T00:00:00`);
    expect(t).toContain(numA);
  });
});
