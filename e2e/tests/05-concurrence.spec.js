// Vraies requêtes SIMULTANÉES : deux serveurs PHP distincts sur la MÊME base MySQL, donc de vraies transactions
// concurrentes (verrous pessimistes). Valide « deux clients, une dernière unité » et l'idempotence en parallèle.
import { test, request as demandes } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { executerSql } from '../lib/db.js';
import { expect, ajouterCookie, stock, cliquerSurPayer, commande as lireCommande } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(() => reinitialiser());

test('deux paiements simultanés pour la dernière unité : un seul est payé, jamais de stock négatif', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const a = await cliquerSurPayer(page); // le stock (1) suffit encore pour chacun au clic : aucune réservation
  const b = await cliquerSurPayer(page);
  executerSql(`UPDATE produit SET stock = 1 WHERE id = ${COOKIE.pistache}`);

  const serveur2 = await demandes.newContext({ baseURL: process.env.E2E_BASE_URL2 });
  const [ra, rb] = await Promise.all([poster(page.request, paiement(a)), poster(serveur2, paiement(b))]);
  await serveur2.dispose();

  expect([ra.corps.status, rb.corps.status].sort()).toEqual(['a_verifier', 'payee']);
  expect(stock(COOKIE.pistache)).toBe(0); // jamais négatif
  expect([lireCommande(a.id).statut, lireCommande(b.id).statut].sort()).toEqual(['a_verifier', 'payee']);
});

test('le même événement livré en même temps aux deux serveurs : traité une seule fois (idempotence)', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 2);
  const c = await cliquerSurPayer(page);
  const evenement = paiement(c);

  const serveur2 = await demandes.newContext({ baseURL: process.env.E2E_BASE_URL2 });
  const [r1, r2] = await Promise.all([poster(page.request, evenement), poster(serveur2, evenement)]);
  await serveur2.dispose();

  expect([r1.corps.status, r2.corps.status].sort()).toEqual(['deja_traitee', 'payee']);
  expect(stock(COOKIE.pistache)).toBe(48); // retiré une seule fois
  expect(lireCommande(c.id).statut).toBe('payee');
});
