import { rmSync } from 'node:fs';
// Remet la base de test dans un état CONNU avant chaque scénario.
import { executerSql } from './db.js';

export const COOKIE = { pistache: 1, peche: 2, noisette: 3, macadamia: 4, fruitsRouges: 5 };
export const BOX = { gourmande: 1, decouverte: 2, surprise: 3, personnalisable: 4, prestige: 5 };

export function reinitialiser() {
  // Les PDF de factures des tests précédents sont supprimés avec les lignes de la table facture (même numérotation depuis 1)
  if (process.env.E2E_FACTURES_DIR) rmSync(process.env.E2E_FACTURES_DIR, { recursive: true, force: true });
  executerSql(`
    SET FOREIGN_KEY_CHECKS=0;
    DELETE FROM facture; DELETE FROM facture_compteur;
    DELETE FROM composition_box_personnalisable; DELETE FROM ligne_commande; DELETE FROM commande;
    DELETE FROM composition_panier_personnalisable; DELETE FROM ligne_panier; DELETE FROM panier;
    SET FOREIGN_KEY_CHECKS=1;
    UPDATE produit SET stock = 50, disponible = 1;
    UPDATE box SET is_active = 1;
  UPDATE box SET taux_tva = 5.50; UPDATE produit SET taux_tva = 5.50;
    UPDATE box SET prix6 = 12.99, prix12 = 19.99, prix24 = 29.99 WHERE id = ${BOX.personnalisable};
    UPDATE box_composition SET quantite = 1;
    UPDATE box_composition SET quantite = 2 WHERE box_id = ${BOX.gourmande} AND produit_id = ${COOKIE.macadamia};
    DELETE FROM adresse WHERE user_id = (SELECT id FROM user WHERE email = 'autre@perline.test');
    DELETE FROM user WHERE email = 'autre@perline.test';
    DELETE FROM adresse WHERE user_id = (SELECT id FROM user WHERE email = 'e2e@perline.test');
    INSERT INTO adresse (user_id, nom, rue, complement, code_postal, ville, pays, par_defaut, created_at, telephone)
      SELECT id, 'Domicile', '12 rue des Tests', 'Bat. B', '34000', 'Montpellier', 'France', 1, NOW(), '06 12 34 56 78' FROM user WHERE email = 'e2e@perline.test';
  `);
}

/** Deuxième client (même mot de passe que le client de test) pour les tests d'accès croisés ; supprimé à chaque réinitialisation. */
export function creerAutreClient() {
  executerSql(`INSERT INTO user (email, roles, password, nom, prenom, points_fidelite, date_inscription)
    SELECT 'autre@perline.test', '["ROLE_USER"]', password, 'Autre', 'Client', 0, NOW() FROM user WHERE email = 'e2e@perline.test'`);
}
