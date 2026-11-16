// Compte client : badge de statut pour TOUS les statuts, boutons facture / avoir.
import { test } from '@playwright/test';
import { reinitialiser, COOKIE } from '../lib/etat.js';
import { executerSql } from '../lib/db.js';
import { expect, ajouterCookie, cliquerSurPayer } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

const LIBELLES = {
  en_attente: 'En attente', payee: 'Payée', confirmee: 'Confirmée', en_preparation: 'En préparation', expediee: 'Expédiée',
  livree: 'Livrée', annulee: 'Annulée', a_verifier: 'À vérifier', remboursee: 'Remboursée', retournee: 'Retournée',
};

test('« Mes commandes » affiche le bon badge pour chaque statut (jamais « Inconnu »)', async ({ page }) => {
  await ajouterCookie(page.request, COOKIE.pistache, 1);
  const c = await cliquerSurPayer(page, 'livraison');
  expect(c).not.toBeNull();

  for (const [statut, libelle] of Object.entries(LIBELLES)) {
    executerSql(`UPDATE commande SET statut = '${statut}' WHERE id = ${c.id}`);
    await page.goto('/user/mon-compte');
    const badge = (await page.locator('#tab-commandes .order-item .badge').first().textContent()).trim();
    expect(badge, statut).toBe(libelle);
  }
  expect(await page.locator('#tab-commandes').evaluate((n) => n.textContent)).not.toContain('Inconnu');
});
