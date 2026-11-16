// Bloc 4 du chapitre 1.3 : expédition et suivi (transporteur, numéro, dates automatiques, e-mail, compte) et statut « Retournée ».
import { test } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { executerSql, lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer, emails, viderEmails, commande as lireCommande } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

async function commandeEnPreparation(browser, mode = 'livraison') {
  const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
  const page = await contexte.newPage();
  await ajouterCookie(page.request, COOKIE.pistache, 2);
  const c = await cliquerSurPayer(page, mode);
  await poster(page.request, paiement(c));
  await contexte.close();
  executerSql(`UPDATE commande SET statut = 'en_preparation' WHERE id = ${c.id}`);
  await viderEmails();

  return c;
}

test.describe('administration', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  // Une seule connexion pour tout le fichier : la page de connexion limite le nombre d'essais par minute
  let etatAdmin;
  test.beforeAll(async ({ browser }) => {
    test.setTimeout(180_000);
    const contexte = await browser.newContext({ baseURL: process.env.E2E_BASE_URL });
    const p = await contexte.newPage();
    try {
      await connecterAdmin(p);
    } catch {
      await p.waitForTimeout(65_000); // limite d'essais de connexion atteinte par les fichiers précédents : on attend la fin de la minute
      await connecterAdmin(p);
    }
    etatAdmin = await contexte.storageState();
    await contexte.close();
  });

  async function connecterAdmin(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', 'admin@creaself.com');
    await page.fill('input[name="password"]', 'admin1234');
    await page.press('input[name="password"]', 'Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });
  }

  async function utiliserAdmin(page) {
    await page.context().addCookies(etatAdmin.cookies);
  }

  async function modifier(page, id, statut, champs = {}) {
    await page.goto(`/admin/commande/${id}/edit`);
    await expect(page.locator('select[name="Commande[statut]"]')).toBeAttached();
    await page.selectOption('select[name="Commande[statut]"]', { label: statut }, { force: true });
    for (const [nom, valeurChamp] of Object.entries(champs)) await page.fill(`[name="Commande[${nom}]"]`, valeurChamp);
    await page.locator('.action-saveAndReturn').first().click();
    await page.waitForLoadState('networkidle');
  }

  const col = (id, nom) => valeur(`SELECT ${nom} FROM commande WHERE id = ${id}`);

  test('expédition : dates et transporteur automatiques, numéro normalisé, un seul e-mail avec le lien de suivi', async ({ page, browser }) => {
    const c = await commandeEnPreparation(browser);
    await utiliserAdmin(page);

    await modifier(page, c.id, 'Expédiée', { numeroSuivi: '6a 123 456 789' });

    expect(lireCommande(c.id).statut).toBe('expediee');
    expect(col(c.id, 'numero_suivi')).toBe('6A123456789');
    expect(col(c.id, 'transporteur')).toBe('Colissimo');
    expect(col(c.id, 'date_expedition')).not.toBeNull();
    await expect.poll(async () => (await emails()).length).toBe(1);
    const message = (await emails())[0];
    const detail = await (await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/message/${message.ID}`)).json();
    expect(detail.HTML).toContain('6A123456789');
    expect(detail.HTML).toContain('laposte.fr');

    // Nouvelle sauvegarde sans changement de statut : pas de second e-mail
    await modifier(page, c.id, 'Expédiée', { numeroSuivi: '6A123456789' });
    expect((await emails()).length).toBe(1);

    // Livrée : date de livraison automatique
    await modifier(page, c.id, 'Livrée');
    expect(col(c.id, 'date_livraison')).not.toBeNull();
  });

  test('retrait sur place : aucun e-mail d\'expédition', async ({ page, browser }) => {
    const c = await commandeEnPreparation(browser, 'retrait');
    await utiliserAdmin(page);
    await modifier(page, c.id, 'Expédiée');
    expect(lireCommande(c.id).statut).toBe('expediee');
    expect((await emails()).length).toBe(0);
  });

  test('Retournée : motif obligatoire, inscrit dans l\'historique ; message dans le compte du client', async ({ page, browser }) => {
    const c = await commandeEnPreparation(browser);
    await utiliserAdmin(page);
    await modifier(page, c.id, 'Expédiée', { numeroSuivi: 'AB123456789' });

    await modifier(page, c.id, 'Retournée'); // sans motif : refusé
    await expect(page.locator('body')).toContainText('indiquez le motif du retour');
    expect(lireCommande(c.id).statut).toBe('expediee');

    await modifier(page, c.id, 'Retournée', { noteChangementStatut: 'Destinataire absent, colis revenu' });
    expect(lireCommande(c.id).statut).toBe('retournee');
    const derniere = lignes(`SELECT nouveau_statut, note FROM commande_historique WHERE commande_id = ${c.id} ORDER BY id DESC LIMIT 1`)[0];
    expect(derniere[0]).toBe('retournee');
    expect(derniere[1]).toContain('Destinataire absent');

    const contexte = await browser.newContext({ storageState: '.auth/utilisateur.json', baseURL: process.env.E2E_BASE_URL });
    const client = await contexte.newPage();
    await client.goto('/user/mon-compte');
    const texte = await client.locator('body').evaluate((n) => n.textContent);
    expect(texte).toContain('Votre colis nous a été retourné');
    expect(texte).toContain('AB123456789');
    expect(await client.locator('a[href*="laposte.fr"][href*="AB123456789"]').count()).toBeGreaterThan(0);
    await contexte.close();
  });
});
