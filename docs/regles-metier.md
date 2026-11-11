# Règles métier du panier, de la commande et du stock

Document de référence (décisions validées avec Dany). À tenir à jour à chaque changement de règle.

## 1. Règle de stock : « contrôle au clic, retrait au paiement » (décidée le 11 novembre 2026)

Le stock ne porte que sur les **cookies**. Une box n'a pas de stock propre : elle retire de chaque cookie
la quantité prévue par sa recette (box classique : table `box_composition`) ou par le choix du client
(box personnalisable). La colonne `box.stock` n'est plus utilisée.

| Moment | Ce qui se passe | Où dans le code |
|---|---|---|
| Ajout au panier | Refus si la quantité demandée, **cumulée avec tout le panier** (cookies seuls, boxes classiques, box perso), dépasse le stock d'un cookie concerné. Une box désactivée ou sans recette ne s'ajoute pas. | `PanierService::ajouterProduit`, `ajouterBox`, `ajouterBoxPersonnalisable` |
| Boutons « + » / « − » | Une hausse est refusée si le stock manque (même cumul), si la box est désactivée, ou si la variation dépasse ±99. Une baisse est toujours possible. | `PanierService::modifierQuantiteLigne` / `modifierQuantiteSession` |
| Clic sur « Payer » | Nouveau contrôle complet (boxes encore vendables, stock de chaque cookie, cumulé). Si un cookie manque : **409** avec le nom du cookie et les quantités, **aucune commande créée**. Les prix sont ceux du jour (recalculés côté serveur). | `PaiementController::createCheckoutSession`, `StockCookiesService` |
| Création de la session Stripe | Commande « En attente » avec lignes **figées** (cookies, boxes, composition des boxes). **Aucune réservation de stock.** | `PaiementController`, `CommandeService` |
| Paiement confirmé (webhook) | Stock **retiré** d'après la composition figée, sous verrou, dans la transaction qui passe la commande à « Payée ». C'est le seul moment où le stock baisse. | `PaiementCommandeService::finaliser` |

### Conséquence assumée : pas de réservation
Entre le clic sur « Payer » et le paiement, un autre client peut acheter le dernier exemplaire.
Si cela arrive, le second paiement trouve un stock insuffisant : il n'est **jamais** retiré en négatif,
la commande passe **« À vérifier »** (paiement encaissé, rien retiré, panier conservé, log critique, réponse 200
au webhook) et un humain décide (remboursement dans le tableau de bord Stripe, voir le chapitre 1.2 bloc 4).

Alternative écartée : réserver le stock pendant 15 à 30 minutes (ajoute une expiration, un nettoyage et des cas
limites pour un gain faible sur une petite boutique). À revoir si les cas « À vérifier » deviennent fréquents.

## 2. Panier

- **Quantités** : jamais nulles ni négatives à l'ajout ; une ligne qui tombe à 0 est supprimée.
- **Prix** : toujours recalculés côté serveur à partir du catalogue (`LignePanier::getPrixUnitaire`). Le navigateur
  n'envoie jamais de prix.
- **Après un paiement** : seuls les articles payés sortent du panier, ligne par ligne et quantité par quantité. Ce qui a été
  ajouté depuis (par exemple dans un autre onglet) reste.
- **Durées** : panier d'un client connecté 30 jours ; panier invité en session (10 min, plafonné à 24 min par PHP).
  Le panier invité en base avec jeton dans un cookie dédié est prévu avec l'achat invité (après le chapitre 1.6).

## 3. Box personnalisable

Le serveur valide la composition reçue du navigateur (jamais de confiance) :
- taille 6, 12 ou 24 ;
- quantités **entières et positives** (les 0 sont ignorés), somme égale à la taille ;
- chaque cookie existe, est disponible, et son stock suffit (cumulé avec le panier) ;
- sinon : refus avec un message clair, rien n'est enregistré.

Faille corrigée le 11 novembre 2026 : une composition `{"1": 10, "2": -4}` (somme 6) passait et donnait 10 cookies pour
le prix de 6 (tests `PanierBoxPersoValidationTest`).

## 4. Prix de la box personnalisable (décidé le 11 novembre 2026)

**Une seule source : les prix saisis dans l'admin** (champs « Prix (6 cookies) », « Prix (12 cookies) »,
« Prix (24 cookies) » de la box personnalisable, colonnes `prix6`, `prix12`, `prix24`).

- Ce prix est celui **affiché** (page `/box`), celui du **panier** et celui **facturé** (Stripe, commande figée) :
  tout passe par `Box::getPrixPourTaille()`.
- Il n'y a **aucun prix de repli**. Le champ « prix » de la box (19,90 €) ne sert qu'aux boxes classiques ; il est ignoré
  pour la box personnalisable. Les anciennes constantes (12 / 24 / 30 €, dupliquées dans `PanierService` et `LignePanier`)
  sont supprimées (un test vérifie qu'elles ne reviennent pas).
- Un prix est **obligatoire et supérieur à 0** pour chacune des trois tailles (validation dans l'admin). Si un prix devient
  indéfini malgré tout : la taille n'est plus vendable (ajout refusé), la box est retirée du panier invité, et le paiement est
  bloqué (« Le prix de … n'est pas disponible », aucune commande créée). Jamais de vente à 0 €.
- Le panier invité relit le prix à chaque affichage : un prix modifié dans l'admin s'applique tout de suite.
- Les commandes déjà passées gardent le prix de leur époque (lignes figées).

## 5. Numéro de commande (décidé le 11 novembre 2026)

Format **`PER-AAAAMMJJ-XXXXXX`** (exemple : `PER-20261111-K7M4QZ`), généré par `NumeroCommandeGenerator`.

- `XXXXXX` : 6 caractères tirés avec `random_int()` (générateur sûr) dans l'alphabet `23456789ABCDEFGHJKMNPQRSTUVWXYZ`
  (31 caractères, **sans 0, O, 1, I, L** : on peut le dicter au téléphone sans confusion).
- **Non séquentiel** : il ne révèle pas le volume de ventes. La numérotation séquentielle est réservée aux **factures**
  (obligation légale, chapitre 1.4).
- **Unicité** garantie par un index unique en base (`commande.numero_commande`) ; le générateur vérifie et retente jusqu'à 10 fois
  en cas de collision (31⁶ ≈ 887 millions de combinaisons par jour : une collision est quasi impossible).
- Les anciens numéros (`CMD-…`) ne sont jamais modifiés.

## 6. Statuts de commande : workflow et historique (décidé le 11 novembre 2026)

Le cycle de vie est un **workflow Symfony** (`config/packages/workflow.yaml`, type « state machine »). Aucun changement de
statut n'est possible en dehors de ces transitions ; tout passe par `ChangementStatutCommande`.

| Transition | De | Vers |
|---|---|---|
| payer | En attente | Payée |
| signaler_ecart | En attente, Annulée | À vérifier |
| valider_verification | À vérifier | Payée |
| confirmer | Payée | Confirmée |
| preparer | Payée, Confirmée | En préparation |
| expedier | En préparation | Expédiée |
| livrer (livrée ou retirée sur place) | En préparation, Expédiée | Livrée |
| annuler | En attente, Payée, Confirmée, En préparation, À vérifier | Annulée |
| rembourser | Payée, Confirmée, En préparation, Expédiée, Livrée, À vérifier, Annulée | Remboursée |

« Remboursée » est un statut final. Aucun retour en arrière (une livrée ne redevient pas « En attente »).

**Historique** (table `commande_historique`) : une ligne par changement, avec la **date**, le statut **avant** et **après**, la
**source** (`client`, `admin`, `webhook_stripe`, `rattrapage`, `systeme`), l'**auteur** (e-mail du client ou de l'administrateur) et
une **note** (par exemple « Écart : montant_different »). Elle est écrite automatiquement pour tout changement (création
comprise), quel que soit le chemin, et s'affiche sur la page de détail de la commande dans l'administration.

**Administration** : le formulaire d'édition n'accepte qu'une transition autorisée ; sinon le changement est refusé avec la liste
des statuts possibles.

## 7. Remboursements et remise en stock (décidé le 11 novembre 2026)

- **Le remboursement se fait dans le tableau de bord Stripe.** Stripe envoie alors `charge.refunded` au webhook
  (à ajouter aux événements du point d'entrée : `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`).
- **Remboursement total** → la commande passe **« Remboursée »**.
- **Remboursement partiel** → le **montant remboursé** (cumulé) est enregistré sur la commande, une **note** est ajoutée à
  l'historique, le statut **ne change pas**. Un remboursement partiel suivi d'un total aboutit à « Remboursée ».
- **Idempotent** : un événement rejoué, ou un ancien événement arrivé après un plus récent, ne change rien.
- La commande est retrouvée par l'identifiant du paiement Stripe (`payment_intent`, mémorisé au paiement) ; pour une commande
  antérieure, via la session Stripe. Un remboursement pour un paiement inconnu est journalisé en critique.
- **La remise en stock n'est JAMAIS automatique** (produits alimentaires : un cookie annulé ou rendu n'est pas forcément
  revendable). L'administrateur coche « **Stock remis en vente** » sur la commande : possible une seule fois, irréversible, seulement
  pour une commande **annulée ou remboursée** dont le stock avait été retiré par le système. La remise suit la **composition
  figée** de la commande, et une note est ajoutée à l'historique.
- Une commande « En attente » qui expire n'a jamais retiré de stock : il n'y a rien à remettre.
