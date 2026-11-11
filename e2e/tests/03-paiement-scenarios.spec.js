// Scénarios de paiement (grille S1 à S6 du chapitre 1.1) automatisés SANS saisir de carte sur la page Stripe :
// le clic sur « Payer » crée vraiment la commande et la session Stripe de test ; la redirection vers Stripe est
// coupée ; le webhook est ensuite envoyé, signé, comme le ferait Stripe.
// Reste MANUEL : S1 (carte refusée 4000 0000 0000 0002) car elle se joue sur la page hébergée par Stripe.
import { test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import {
  expect, ajouterCookie, quantiteEnPanier, stock, nombreDeCommandes, cliquerSurPayer, commande as lireCommande,
  derniereCommande, emails, viderEmails,
} from '../lib/aide.js';
import { construire, expiration, paiement, poster } from '../lib/stripe.js';

test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

async function commandeEnAttente(page, quantite = 1) {
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page);
  expect(c).not.toBeNull();
  expect(c.statut).toBe('en_attente');

  return c;
}

test('paiement normal : commande payée, stock retiré une fois, panier vidé, e-mail, page de succès', async ({ page }) => {
  const c = await commandeEnAttente(page, 2);
  expect(c.total).toBeGreaterThan(0);

  const r = await poster(page.request, paiement(c));

  expect(r).toEqual({ statut: 200, corps: { status: 'payee' } });
  expect(lireCommande(c.id).statut).toBe('payee');
  expect(stock(COOKIE.pistache)).toBe(48);
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(0);
  await expect.poll(async () => (await emails()).length).toBe(1);

  await page.goto(`/paiement/success-stripe?session_id=${c.session}`);
  await expect(page.locator('body')).toContainText(c.numero);
});

test('signature Stripe invalide : webhook refusé (400), rien ne change', async ({ page }) => {
  const c = await commandeEnAttente(page);

  const r = await poster(page.request, paiement(c), 'whsec_un_autre_secret');

  expect(r.statut).toBe(400);
  expect(lireCommande(c.id).statut).toBe('en_attente');
  expect(stock(COOKIE.pistache)).toBe(50);
});

test('S2 annulation : retour au panier intact, commande « En attente », stock inchangé', async ({ page }) => {
  const c = await commandeEnAttente(page, 2);

  await page.goto('/panier'); // = bouton « Retour » de la page Stripe (cancel_url)

  await expect(page.locator('.cart-row')).toHaveCount(1);
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(2);
  expect(lireCommande(c.id).statut).toBe('en_attente');
  expect(stock(COOKIE.pistache)).toBe(50);
});

test('S3 session expirée : « Annulée », jamais supprimée, stock et panier inchangés', async ({ page }) => {
  const c = await commandeEnAttente(page, 2);

  const r = await poster(page.request, expiration(c));

  expect(r.corps.status).toBe('annulee');
  expect(lireCommande(c.id).statut).toBe('annulee');
  expect(nombreDeCommandes()).toBe(1);
  expect(stock(COOKIE.pistache)).toBe(50);
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(2);
});

test('S4 expiration rejouée : « déjà traité », la commande reste annulée', async ({ page }) => {
  const c = await commandeEnAttente(page);
  const evenement = expiration(c);

  expect((await poster(page.request, evenement)).corps.status).toBe('annulee');
  expect((await poster(page.request, evenement)).corps.status).toBe('deja_traitee');
  expect(lireCommande(c.id).statut).toBe('annulee');
});

test('S5 webhook en retard : message d\'attente, puis paiement traité une seule fois (stock, e-mail)', async ({ page }) => {
  const c = await commandeEnAttente(page, 1);

  // Aucun webhook reçu : la page de succès attend, puis prévient le client au lieu d'un faux « Félicitations »
  await page.goto(`/paiement/success-stripe?session_id=${c.session}`);
  await expect(page.locator('[role="alert"]').first()).toContainText('en cours de confirmation');
  expect(lireCommande(c.id).statut).toBe('en_attente');
  expect(stock(COOKIE.pistache)).toBe(50);
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(1);

  // Le webhook arrive enfin (et une seconde fois : rejeu)
  const evenement = paiement(c);
  expect((await poster(page.request, evenement)).corps.status).toBe('payee');
  expect((await poster(page.request, evenement)).corps.status).toBe('deja_traitee');

  expect(lireCommande(c.id).statut).toBe('payee');
  expect(stock(COOKIE.pistache)).toBe(49);
  expect((await emails()).length).toBe(1);
});

test('écart de montant : « À vérifier », stock et panier intacts, aucun e-mail', async ({ page }) => {
  const c = await commandeEnAttente(page, 2);

  const r = await poster(page.request, paiement(c, { montant: Math.round(c.total * 100) - 100 }));

  expect(r.corps.status).toBe('a_verifier');
  expect(lireCommande(c.id).statut).toBe('a_verifier');
  expect(stock(COOKIE.pistache)).toBe(50);
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(2);
  expect((await emails()).length).toBe(0);
});

test('paiement reçu sur une commande déjà annulée : jamais ignoré, « À vérifier »', async ({ page }) => {
  const c = await commandeEnAttente(page);
  await poster(page.request, expiration(c));

  const r = await poster(page.request, paiement(c));

  expect(r.corps.status).toBe('a_verifier');
  expect(lireCommande(c.id).statut).toBe('a_verifier');
  expect(stock(COOKIE.pistache)).toBe(50);
});

test('paiement sans référence de commande : aucune commande créée à l\'aveugle', async ({ page }) => {
  const r = await poster(page.request, construire('checkout.session.completed', {
    id: 'cs_test_sans_reference', payment_status: 'paid', currency: 'eur', amount_total: 1000,
  }));

  expect(r.corps.status).toBe('sans_reference');
  expect(nombreDeCommandes()).toBe(0);
});

test('S6 nettoyage : seules les « En attente » anciennes sont supprimées', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const ids = [];
  for (let i = 0; i < 4; i++) ids.push((await cliquerSurPayer(page)).id);
  const [enAttente, annulee, aVerifier, payee] = ids;
  executerSql(`
    UPDATE commande SET statut = 'annulee' WHERE id = ${annulee};
    UPDATE commande SET statut = 'a_verifier' WHERE id = ${aVerifier};
    UPDATE commande SET statut = 'payee' WHERE id = ${payee};
    UPDATE commande SET date_commande = NOW() - INTERVAL 3 DAY;
  `);

  const executer = (args) => execFileSync('php', ['bin/console', 'app:nettoyer-commandes-en-attente', ...args], {
    cwd: process.env.E2E_RACINE,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'ignore'],
    env: { ...process.env, APP_ENV: 'e2e', APP_DEBUG: '0', DATABASE_URL: process.env.E2E_DATABASE_URL },
  });

  const simulation = executer(['--dry-run']);
  expect(simulation).toContain(valeur(`SELECT numero_commande FROM commande WHERE id = ${enAttente}`));
  for (const id of [annulee, aVerifier, payee]) {
    expect(simulation).not.toContain(valeur(`SELECT numero_commande FROM commande WHERE id = ${id}`));
  }
  expect(nombreDeCommandes()).toBe(4); // la simulation ne supprime rien

  executer([]);
  expect(nombreDeCommandes()).toBe(3);
  expect(lireCommande(enAttente)).toBeNull();
  for (const id of [annulee, aVerifier, payee]) expect(lireCommande(id)).not.toBeNull();
});
