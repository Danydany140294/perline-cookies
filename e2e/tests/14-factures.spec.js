// Bloc 3 du chapitre 1.4 : factures et avoirs (numérotation séquentielle, PDF stocké hors public, téléchargement protégé).
import { test, request } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { lignes, valeur } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer, emails, viderEmails, commande as lireCommande } from '../lib/aide.js';
import { paiement, poster, remboursement } from '../lib/stripe.js';

test.beforeEach(async () => { reinitialiser(); await viderEmails(); });

const annee = new Date().toLocaleString('fr-FR', { timeZone: 'Europe/Paris', year: 'numeric' });
const cheminPdf = (numero) => path.join(process.env.E2E_FACTURES_DIR, numero.split('-')[1], `${numero}.pdf`);
const texteMiseEnPage = (numero) => execFileSync('pdftotext', ['-layout', '-enc', 'UTF-8', cheminPdf(numero), '-'], { encoding: 'utf8' });
const texte = (numero) => execFileSync('pdftotext', ['-enc', 'UTF-8', cheminPdf(numero), '-'], { encoding: 'utf8' }).replace(/\s+/g, ' ');
const factures = () => lignes('SELECT numero, type, total_ttc, demo FROM facture ORDER BY id').map(([numero, type, ttc, demo]) => ({ numero, type, ttc: Number(ttc), demo: demo === '1' }));

async function commandePayee(page, quantite = 2) {
  await ajouterCookie(page.request, COOKIE.pistache, quantite);
  const c = await cliquerSurPayer(page, 'livraison');
  expect(c).not.toBeNull();
  await poster(page.request, paiement(c));

  return c;
}

test('paiement : facture F-AAAA-000001 émise une seule fois, PDF stocké hors public, joint à l\'e-mail de confirmation', async ({ page }) => {
  const c = await commandePayee(page);
  const numero = `F-${annee}-000001`;

  expect(factures()).toEqual([{ numero, type: 'facture', ttc: Number(c.total.toFixed(2)), demo: true }]);
  expect(existsSync(cheminPdf(numero))).toBe(true);
  const pdf = readFileSync(cheminPdf(numero));
  expect(pdf.subarray(0, 4).toString()).toBe('%PDF');
  expect(valeur(`SELECT empreinte FROM facture WHERE numero = '${numero}'`)).toBe(createHash('sha256').update(pdf).digest('hex'));

  const t = texte(numero);
  expect(t).toContain(`FACTURE ${numero}`);
  expect(t).toContain('Perline Cookies SAS (exemple)');
  expect(t).toContain('FR00 000000000');
  expect(t).toContain('Total HT');
  expect(t).toContain('TVA 5,50 %');
  expect(t).toContain('Total TTC');
  // Tous les articles ont le même taux : la ligne des frais affiche ce taux (pas de « — » ni de prorata)
  const ligneFrais = texteMiseEnPage(numero).split('\n').find((l) => l.includes('Frais de livraison'));
  expect(ligneFrais).toContain('5,50 %');
  expect(t).not.toContain('prorata');
  expect(t).toContain('Document de démonstration, sans valeur fiscale');

  // Rejeu du webhook : aucune seconde facture
  await poster(page.request, paiement(c));
  expect(factures()).toHaveLength(1);

  // L'e-mail de confirmation porte la facture en pièce jointe
  await expect.poll(async () => (await emails()).length).toBe(1);
  const detail = await (await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/message/${(await emails())[0].ID}`)).json();
  expect(detail.Attachments.map((a) => a.FileName)).toEqual([`${numero}.pdf`]);

  // Le PDF n'est pas accessible directement par le web
  for (const url of [`/var/factures/${annee}/${numero}.pdf`, `/factures/${numero}.pdf`]) {
    expect((await page.request.get(url, { maxRedirects: 0 })).status()).toBeGreaterThanOrEqual(400);
  }
});

test('deux paiements simultanés : numéros consécutifs, sans trou ni doublon', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const c1 = await cliquerSurPayer(page, 'livraison');
  await ajouterCookie(page.request, COOKIE.peche, 1);
  const c2 = await cliquerSurPayer(page, 'livraison');
  expect(c1.id).not.toBe(c2.id);

  await Promise.all([poster(page.request, paiement(c1)), poster(page.request, paiement(c2))]);

  expect(factures().map((f) => f.numero).sort()).toEqual([`F-${annee}-000001`, `F-${annee}-000002`]);
  expect(valeur(`SELECT derniere_sequence FROM facture_compteur WHERE type = 'facture' AND annee = ${annee}`)).toBe('2');
});

test('téléchargement : le propriétaire reçoit le PDF, un autre compte ou un visiteur non', async ({ page, browser }) => {
  const c = await commandePayee(page);
  const idFacture = valeur(`SELECT id FROM facture WHERE commande_id = ${c.id}`);

  const proprietaire = await page.request.get(`/compte/factures/${idFacture}/telecharger`);
  expect(proprietaire.status()).toBe(200);
  expect(proprietaire.headers()['content-type']).toContain('application/pdf');
  expect(proprietaire.headers()['content-disposition']).toContain(`F-${annee}-000001.pdf`);
  expect((await proprietaire.body()).subarray(0, 4).toString()).toBe('%PDF');

  // Visiteur non connecté : renvoyé vers la connexion
  const visiteur = await request.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  const anonyme = await visiteur.get(`/compte/factures/${idFacture}/telecharger`, { maxRedirects: 0 });
  expect([301, 302, 401, 403]).toContain(anonyme.status());
  await visiteur.dispose();

  // Autre compte connecté (l'administrateur n'est pas le propriétaire) : 404
  // storageState vide : sinon le contexte réutiliserait la session du client (même cookie) et la connexion admin la remplacerait
  const contexte = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: { cookies: [], origins: [] } });
  const admin = await contexte.newPage();
  await admin.goto('/login');
  await admin.fill('input[name="email"]', 'admin@creaself.com');
  await admin.fill('input[name="password"]', 'admin1234');
  await admin.press('input[name="password"]', 'Enter');
  await admin.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });
  expect((await admin.request.get(`/compte/factures/${idFacture}/telecharger`)).status()).toBe(404);
  await contexte.close();

  // Le compte du client propose le lien
  await page.goto('/user/mon-compte');
  const bouton = page.locator(`a.btn[href="/compte/factures/${idFacture}/telecharger"]`);
  await expect(bouton).toHaveCount(1);
  await expect(bouton).toContainText('Télécharger la facture');
  await expect(page.locator('#tab-commandes .order-item .badge').first()).toHaveText(/Payée/);
});

test('remboursement : avoir A-AAAA-000001 qui référence la facture ; partiel puis total = deux avoirs ; rejeu sans effet', async ({ page }) => {
  const c = await commandePayee(page);
  const numeroFacture = `F-${annee}-000001`;
  await viderEmails();

  await poster(page.request, remboursement(c, 500)); // 5,00 € remboursés
  let liste = factures();
  expect(liste.map((f) => f.numero)).toEqual([numeroFacture, `A-${annee}-000001`]);
  expect(liste[1].ttc).toBeCloseTo(-5, 2);
  expect(valeur(`SELECT f.numero FROM facture a JOIN facture f ON f.id = a.facture_origine_id WHERE a.numero = 'A-${annee}-000001'`)).toBe(numeroFacture);
  expect(texte(`A-${annee}-000001`)).toContain(`AVOIR A-${annee}-000001`);
  expect(texte(`A-${annee}-000001`)).toContain(numeroFacture);

  await poster(page.request, remboursement(c, 500)); // même événement rejoué : rien de plus
  expect(factures()).toHaveLength(2);

  await poster(page.request, remboursement(c, Math.round(c.total * 100))); // remboursement total (cumulé)
  liste = factures();
  expect(liste.map((f) => f.numero)).toEqual([numeroFacture, `A-${annee}-000001`, `A-${annee}-000002`]);
  expect(liste[1].ttc + liste[2].ttc).toBeCloseTo(-c.total, 2); // les avoirs couvrent exactement la facture
  expect(lireCommande(c.id).statut).toBe('remboursee');

  await expect.poll(async () => (await emails()).length).toBe(2); // un e-mail par avoir

  // Le compte propose la facture ET les deux avoirs (boutons), avec le statut « Remboursée »
  await page.goto('/user/mon-compte');
  await expect(page.locator('#tab-commandes .order-item .badge').first()).toHaveText(/Remboursée/);
  await expect(page.locator('#tab-commandes a.btn', { hasText: 'Télécharger la facture' })).toHaveCount(1);
  await expect(page.locator('#tab-commandes a.btn', { hasText: `Télécharger l'avoir A-${annee}-000001` })).toHaveCount(1);
  await expect(page.locator('#tab-commandes a.btn', { hasText: `Télécharger l'avoir A-${annee}-000002` })).toHaveCount(1);
});

test('taux mélangés : la ligne des frais affiche « — » et la mention du prorata', async ({ page }) => {
  const { executerSql } = await import('../lib/db.js');
  executerSql(`UPDATE produit SET taux_tva = 20.00 WHERE id = ${COOKIE.peche}`);
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  await ajouterCookie(page.request, COOKIE.peche, 1);
  const c = await cliquerSurPayer(page, 'livraison');
  await poster(page.request, paiement(c));

  const numero = `F-${annee}-000001`;
  // (le filigrane de démonstration s'intercale dans le texte : on cherche la ligne qui porte le montant des frais)
  expect(texteMiseEnPage(numero).split('\n').some((l) => l.includes('4,50') && l.includes('—'))).toBe(true);
  expect(texte(numero)).toContain('prorata');
  expect(texte(numero)).toContain('TVA 20,00 %');
});

test('mode démo inactif : facture réelle, sans filigrane', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 2);
  const c = await cliquerSurPayer(page, 'livraison');
  const serveurReel = await request.newContext({ baseURL: process.env.E2E_BASE_URL2 }); // BOUTIQUE_MODE_DEMO=0
  await poster(serveurReel, paiement(c));
  await serveurReel.dispose();

  const [facture] = factures();
  expect(facture.demo).toBe(false);
  expect(texte(facture.numero)).not.toContain('démonstration');
});
