// Remet la base de test dans un état CONNU avant chaque scénario.
import { executerSql } from './db.js';

export const COOKIE = { pistache: 1, peche: 2, noisette: 3, macadamia: 4, fruitsRouges: 5 };
export const BOX = { gourmande: 1, decouverte: 2, surprise: 3, personnalisable: 4, prestige: 5 };

export function reinitialiser() {
  executerSql(`
    SET FOREIGN_KEY_CHECKS=0;
    DELETE FROM composition_box_personnalisable; DELETE FROM ligne_commande; DELETE FROM commande;
    DELETE FROM composition_panier_personnalisable; DELETE FROM ligne_panier; DELETE FROM panier;
    SET FOREIGN_KEY_CHECKS=1;
    UPDATE produit SET stock = 50, disponible = 1;
    UPDATE box SET is_active = 1;
    UPDATE box SET prix6 = 12.99, prix12 = 19.99, prix24 = 29.99 WHERE id = ${BOX.personnalisable};
    UPDATE box_composition SET quantite = 1;
    UPDATE box_composition SET quantite = 2 WHERE box_id = ${BOX.gourmande} AND produit_id = ${COOKIE.macadamia};
  `);
}
