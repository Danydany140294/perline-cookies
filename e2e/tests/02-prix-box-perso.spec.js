// Bloc 2 du chapitre 1.2 : le prix de la box personnalisable est celui de l'admin, partout.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE, BOX } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import { expect, ajouterBoxPerso, nombreDeCommandes, bandeau, cliquerSurPayer, derniereCommande } from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(() => reinitialiser());

const TAILLES = [
  { taille: 6, prix: 12.99, texte: '12,99', cookies: { [COOKIE.pistache]: 3, [COOKIE.peche]: 3 } },
  { taille: 12, prix: 19.99, texte: '19,99', cookies: { [COOKIE.pistache]: 6, [COOKIE.peche]: 6 } },
  { taille: 24, prix: 29.99, texte: '29,99', cookies: { [COOKIE.pistache]: 12, [COOKIE.peche]: 12 } },
];

test('/box expose les prix de l\'admin et affiche 19,99 € par défaut', async ({ page }) => {
  await page.goto('/box');
  const prix = page.locator('#box-prices');
  await expect(prix).toHaveAttribute('data-prix6', '12.99');
  await expect(prix).toHaveAttribute('data-prix12', '19.99');
  await expect(prix).toHaveAttribute('data-prix24', '29.99');
  await expect(page.locator('.featured-price .price-value')).toContainText('19,99');
});

for (const { taille, prix, texte, cookies } of TAILLES) {
  test(`box de ${taille} cookies : panier, Stripe et commande au même prix (${texte} €)`, async ({ page }) => {
    expect((await ajouterBoxPerso(page.request, cookies, taille)).statut).toBe(200);

    await page.goto('/panier');
    await expect(page.locator('.cart-row').first()).toContainText(texte);

    const commande = await cliquerSurPayer(page, 'retrait');
    expect(commande).not.toBeNull();
    expect(commande.total).toBeCloseTo(prix, 2); // retrait : pas de frais de livraison

    expect(Number(valeur('SELECT prix_unitaire FROM ligne_commande LIMIT 1'))).toBeCloseTo(prix, 2);
    expect((await poster(page.request, paiement(commande))).corps.status).toBe('payee');
  });
}

test('un prix modifié dans l\'admin s\'applique tout de suite au panier', async ({ page }) => {
  await ajouterBoxPerso(page.request, TAILLES[1].cookies, 12);
  executerSql(`UPDATE box SET prix12 = 21.49 WHERE id = ${BOX.personnalisable}`);

  await page.goto('/panier');
  await expect(page.locator('.cart-row').first()).toContainText('21,49');

  const commande = await cliquerSurPayer(page);
  expect(commande.total).toBeCloseTo(21.49, 2);
});

test('prix de la taille non défini : ajout refusé, et paiement bloqué si le prix disparaît ensuite', async ({ page }) => {
  executerSql(`UPDATE box SET prix6 = NULL WHERE id = ${BOX.personnalisable}`);
  const refus = await ajouterBoxPerso(page.request, TAILLES[0].cookies, 6);
  expect(refus.statut).toBe(400);
  expect(refus.json.message).toContain('n\'est pas disponible pour le moment');

  // Box de 12 déjà au panier, puis prix supprimé avant le paiement : jamais de vente à 0 € ni au prix d'une constante
  executerSql(`UPDATE box SET prix6 = 12.99 WHERE id = ${BOX.personnalisable}`);
  await ajouterBoxPerso(page.request, TAILLES[1].cookies, 12);
  executerSql(`UPDATE box SET prix12 = NULL WHERE id = ${BOX.personnalisable}`);

  const commande = await cliquerSurPayer(page);

  expect(commande).toBeNull();
  await expect(bandeau(page).first()).toContainText('n\'est pas disponible');
  expect(nombreDeCommandes()).toBe(0);
  expect(derniereCommande()).toBeNull();
});
