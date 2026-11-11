// Bloc 1 du chapitre 1.2 : contrôles du panier (quantités, stock cumulé, box perso, box désactivée, deux onglets).
import { test } from '@playwright/test';
import { reinitialiser, COOKIE, BOX } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import {
  expect, ajouterCookie, ajouterBox, ajouterBoxPerso, quantiteEnPanier, stock, nombreDeCommandes,
  surveillerFenetresNatives, bandeau, cliquerSurPayer, derniereCommande,
} from '../lib/aide.js';
import { paiement, poster } from '../lib/stripe.js';

test.beforeEach(() => reinitialiser());

test('page produits : au-delà du stock, un message lisible apparaît, le bouton est réactivé, aucune alert()', async ({ page }) => {
  const fenetres = surveillerFenetresNatives(page);
  executerSql(`UPDATE produit SET stock = 2 WHERE id = ${COOKIE.pistache}`);
  await page.goto('/produits');

  const bouton = page.locator(`form[action$="/panier/ajouter-produit/${COOKIE.pistache}"] button[type="submit"]`);
  for (let i = 0; i < 3; i++) {
    await bouton.click();
    await expect(bouton).toBeEnabled();
    await page.waitForTimeout(400);
  }

  await expect(page.locator('#toast-container')).toContainText('Stock insuffisant pour Cookie Pistache Cannelle');
  await expect(bouton).toContainText('Ajouter au panier');
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(2);
  expect(fenetres).toEqual([]);
});

test('page panier : « + » au plafond du stock -> bandeau, quantité inchangée, aucune alert()', async ({ page }) => {
  const fenetres = surveillerFenetresNatives(page);
  executerSql(`UPDATE produit SET stock = 2 WHERE id = ${COOKIE.pistache}`);
  await ajouterCookie(page.request, COOKIE.pistache, 2);

  await page.goto('/panier');
  const ligneId = valeur(`SELECT id FROM ligne_panier WHERE produit_id = ${COOKIE.pistache}`);
  await page.locator(`.cart-row[data-id="${ligneId}"] button:text-is("+")`).click();

  await expect(bandeau(page).first()).toContainText('Stock insuffisant pour Cookie Pistache Cannelle : 3 demandé(s), 2 disponible(s)');
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(2);
  expect(fenetres).toEqual([]);
});

test('modale du panier : « + » au plafond -> bandeau (plus de fenêtre native)', async ({ page }) => {
  const fenetres = surveillerFenetresNatives(page);
  executerSql(`UPDATE produit SET stock = 2 WHERE id = ${COOKIE.pistache}`);
  await ajouterCookie(page.request, COOKIE.pistache, 2);
  const ligneId = valeur(`SELECT id FROM ligne_panier WHERE produit_id = ${COOKIE.pistache}`);

  await page.goto('/');
  await page.evaluate((id) => panierModal.updateQuantity(Number(id), 'Cookie', 1), ligneId);

  await expect(bandeau(page).first()).toContainText('Stock insuffisant pour Cookie Pistache Cannelle');
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(2);
  expect(fenetres).toEqual([]);
});

test('stock cumulé : cookies seuls + cookies des boxes comptent ensemble', async ({ page }) => {
  executerSql(`UPDATE produit SET stock = 17 WHERE id = ${COOKIE.macadamia}`);
  await ajouterBox(page.request, BOX.gourmande, 2);        // 2 boxes x 2 Macadamia = 4
  expect((await ajouterCookie(page.request, COOKIE.macadamia, 13)).statut).toBe(200); // 4 + 13 = 17 : ok

  const refus = await ajouterCookie(page.request, COOKIE.macadamia, 1); // 18 > 17
  expect(refus.statut).toBe(409);
  expect(refus.json.message).toContain('Stock insuffisant pour Cookie Macadamia Framboise : 18 demandé(s), 17 disponible(s)');
});

test('box personnalisable : composition forgée refusée, composition valide acceptée', async ({ page }) => {
  const negative = await ajouterBoxPerso(page.request, { [COOKIE.pistache]: 10, [COOKIE.peche]: -4 }, 6);
  expect(negative.statut).toBe(400);
  expect(negative.json.message).toContain('la quantité du cookie n°2 doit être un entier positif');

  const inexistant = await ajouterBoxPerso(page.request, { 99999: 6 }, 6);
  expect(inexistant.statut).toBe(400);
  expect(inexistant.json.message).toContain('Cookie introuvable');

  const tropGrand = await ajouterBoxPerso(page.request, { [COOKIE.pistache]: 60 }, 6);
  expect(tropGrand.statut).toBe(400);

  expect(Number(valeur('SELECT COUNT(*) FROM ligne_panier'))).toBe(0);

  const valide = await ajouterBoxPerso(page.request, { [COOKIE.pistache]: 4, [COOKIE.peche]: 2 }, 6);
  expect(valide.statut).toBe(200);
  expect(Number(valeur('SELECT COUNT(*) FROM composition_panier_personnalisable'))).toBe(2);
});

test('variation de quantité absurde refusée par le serveur', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const ligneId = valeur(`SELECT id FROM ligne_panier WHERE produit_id = ${COOKIE.pistache}`);

  const r = await page.request.post('/panier/update-quantity', { data: { id: Number(ligneId), type: 'Cookie', change: 1000 } });

  expect(r.status()).toBe(400);
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(1);
});

test('box désactivée : « Payer » est bloqué, message affiché, aucune commande créée', async ({ page }) => {
  await ajouterBox(page.request, BOX.gourmande, 1);
  executerSql(`UPDATE box SET is_active = 0 WHERE id = ${BOX.gourmande}`);

  const c = await cliquerSurPayer(page);

  expect(c).toBeNull();
  await expect(bandeau(page).first()).toContainText('La box « Box Gourmande » n\'est plus disponible.');
  await expect(page.locator('#checkout-btn')).toBeEnabled();
  expect(nombreDeCommandes()).toBe(0);
});

test('stock insuffisant au clic sur « Payer » : le cookie fautif est nommé, aucune commande, bouton réactivé', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.noisette, 3);
  executerSql(`UPDATE produit SET stock = 2 WHERE id = ${COOKIE.noisette}`);

  const c = await cliquerSurPayer(page);

  expect(c).toBeNull();
  await expect(bandeau(page).first()).toContainText('Stock insuffisant pour Cookie Noisette Vanille : 3 demandé(s), 2 disponible(s)');
  await expect(page.locator('#checkout-btn')).toBeEnabled();
  expect(nombreDeCommandes()).toBe(0);
});

test('deux onglets : seuls les articles payés quittent le panier', async ({ page, context }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 2);
  const commande = await cliquerSurPayer(page);
  expect(commande).not.toBeNull();

  // Onglet 2 : le client ajoute un autre cookie après le clic sur « Payer »
  const onglet2 = await context.newPage();
  await ajouterCookie(onglet2.request, COOKIE.noisette, 1);

  const reponse = await poster(page.request, paiement(commande));
  expect(reponse.corps.status).toBe('payee');

  expect(derniereCommande().statut).toBe('payee');
  expect(stock(COOKIE.pistache)).toBe(48);
  expect(quantiteEnPanier(COOKIE.pistache)).toBe(0);
  expect(quantiteEnPanier(COOKIE.noisette)).toBe(1); // reste dans le panier
});

test('une box retire les cookies de sa recette, composition figée sur la commande', async ({ page }) => {
  await ajouterBox(page.request, BOX.gourmande, 2); // recette : 1 Pistache, 1 Pêche, 2 Macadamia
  const commande = await cliquerSurPayer(page);
  expect((await poster(page.request, paiement(commande))).corps.status).toBe('payee');

  expect(stock(COOKIE.pistache)).toBe(48);   // 2 boxes x 1
  expect(stock(COOKIE.peche)).toBe(48);
  expect(stock(COOKIE.macadamia)).toBe(46);  // 2 boxes x 2

  // La recette change dans l'admin APRÈS la commande : la commande garde l'ancienne composition
  executerSql(`UPDATE box_composition SET quantite = 9 WHERE box_id = ${BOX.gourmande} AND produit_id = ${COOKIE.macadamia}`);
  expect(valeur(`SELECT quantite FROM composition_box_personnalisable WHERE produit_id = ${COOKIE.macadamia}`)).toBe('2');
});
