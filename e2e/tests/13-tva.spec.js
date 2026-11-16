// Bloc 2 du chapitre 1.4 : TVA par produit / box, HT et TVA par taux figés sur la commande, sans changer le prix payé.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE, BOX } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import { expect, ajouterCookie, ajouterBox, cliquerSurPayer, colonneCommande } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

const detail = (id) => JSON.parse(colonneCommande(id, 'tva_detail'));
const somme = (liste, cle) => Math.round(liste.reduce((s, d) => s + Number(d[cle]), 0) * 100) / 100;

test('un seul taux (5,5 % par défaut) : HT + TVA = TTC payé, taux figé sur chaque ligne', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 2);
  const c = await cliquerSurPayer(page, 'livraison');
  expect(c).not.toBeNull();

  const ttc = Number(colonneCommande(c.id, 'total_ttc'));
  const ht = Number(colonneCommande(c.id, 'total_ht'));
  const tva = Number(colonneCommande(c.id, 'total_tva'));
  expect(Math.round((ht + tva) * 100)).toBe(Math.round(ttc * 100));
  expect(detail(c.id)).toHaveLength(1);
  expect(detail(c.id)[0].taux).toBe(5.5);
  expect(valeur(`SELECT taux_tva FROM ligne_commande WHERE commande_id = ${c.id} LIMIT 1`)).toBe('5.50');
});

test('taux mélangés : détail par taux, frais répartis, total TTC inchangé ; modifier le taux ensuite ne touche pas la commande', async ({ page }) => {
  executerSql(`UPDATE produit SET taux_tva = 20.00 WHERE id = ${COOKIE.peche}`);
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  await ajouterCookie(page.request, COOKIE.peche, 1);
  const c = await cliquerSurPayer(page, 'livraison'); // 2 cookies : sous le seuil, frais de 4,50 €
  expect(c).not.toBeNull();

  const d = detail(c.id);
  expect(d.map((x) => x.taux)).toEqual([5.5, 20]);
  const ttc = Number(colonneCommande(c.id, 'total_ttc'));
  expect(somme(d, 'ttc')).toBeCloseTo(ttc, 2);
  expect(somme(d, 'ht') + somme(d, 'tva')).toBeCloseTo(ttc, 2);
  expect(Number(colonneCommande(c.id, 'frais_livraison'))).toBeCloseTo(4.5, 2);

  // Les frais (4,50 €) sont dans les groupes de taux : la somme des TTC par taux dépasse celle des articles
  const articles = Number(valeur(`SELECT SUM(prix_unitaire * quantite) FROM ligne_commande WHERE commande_id = ${c.id}`));
  expect(somme(d, 'ttc')).toBeCloseTo(articles + 4.5, 2);

  // Changement de taux après coup : la commande ne bouge pas
  const avant = colonneCommande(c.id, 'tva_detail');
  executerSql(`UPDATE produit SET taux_tva = 10.00`);
  expect(colonneCommande(c.id, 'tva_detail')).toBe(avant);
  expect(valeur(`SELECT GROUP_CONCAT(taux_tva ORDER BY id) FROM ligne_commande WHERE commande_id = ${c.id}`)).toBe('5.50,20.00');
});

test('une box a son propre taux', async ({ page }) => {
  executerSql(`UPDATE box SET taux_tva = 10.00 WHERE id = ${BOX.gourmande}`);
  await ajouterBox(page.request, BOX.gourmande, 1);
  const c = await cliquerSurPayer(page, 'retrait');
  expect(c).not.toBeNull();

  expect(detail(c.id)).toHaveLength(1);
  expect(detail(c.id)[0].taux).toBe(10);
  expect(Number(colonneCommande(c.id, 'frais_livraison'))).toBe(0);
});
