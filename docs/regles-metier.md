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
