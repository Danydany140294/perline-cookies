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
  En local, la commande à lancer est :
  `./stripe.exe listen --api-key sk_test_XXX --forward-to http://127.0.0.1:8000/webhook/stripe --events checkout.session.completed,checkout.session.expired,charge.refunded`.
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
- **Annuler une commande déjà payée ne la rembourse pas.** Dans l'administration, passer une commande payée (Payée, Confirmée,
  En préparation, À vérifier) à « Annulée » affiche l'avertissement « Cette commande est payée : pense à la rembourser dans
  Stripe. » et l'inscrit dans l'historique. Le remboursement se fait ensuite dans Stripe (le webhook la passera « Remboursée »).
- **La remise en stock ne change jamais le champ « disponible »** d'un produit : un produit désactivé à la main le reste. Seul le
  retrait de stock désactive un produit arrivé à 0 ; le réactiver est un geste de l'administrateur.

## 8. Livraison, zones et frais (décidé le 11 novembre 2026, chapitre 1.3)

**Une seule source : le paramètre `livraison` de `config/services.yaml`.** Il alimente le calcul serveur, le panier, la page de
paiement et les CGV (variable Twig globale `livraison`) : un montant ne peut plus être différent d'une page à l'autre.

| Réglage | Valeur actuelle |
|---|---|
| Modes proposés | livraison à domicile (**Colissimo avec suivi**) et retrait à l'atelier |
| Masquer un mode | variables d'environnement `LIVRAISON_DOMICILE_ACTIF=0` ou `LIVRAISON_RETRAIT_ACTIF=0` (le serveur refuse aussi le mode masqué) |
| Frais de livraison | **forfait 4,50 €** par commande (`frais_forfait_centimes: 450`) |
| Livraison offerte | **dès 50 €** d'articles, seuil inclus (`seuil_gratuite_centimes: 5000`) |
| Retrait sur place | gratuit |
| Zone desservie | **France métropolitaine hors Corse** : pays figé sur France, codes postaux 01 à 95 sauf 20 ; DOM-TOM (97, 98) exclus ; pas d'étranger, pas de zone locale |

Pour ouvrir la Corse plus tard : retirer `'20'` de `zone.departements_exclus`.

- **Une adresse hors zone** (Corse, outre-mer, étranger, code postal inexistant) est refusée avec un message clair :
  « Cette adresse n'est pas desservie : nous ne livrons pas encore la Corse. » Elle est refusée dans le formulaire d'adresse,
  non sélectionnable sur la page de paiement (raison affichée) et refusée par le serveur (`/paiement/choisir-adresse`,
  `/paiement/create-checkout-session`) même si la requête est forgée. Le retrait sur place reste possible sans adresse.
- **Détail avant paiement** : sous-total, « Livraison (Colissimo) » (4,50 € ou offerte), total, et « Plus que X € pour la livraison
  offerte » ; le montant des frais est copié sur la commande (`frais_livraison`).
- **Adresse de livraison copiée dans la commande** au paiement (destinataire, rue, complément, code postal, ville, pays,
  téléphone) : modifier ou supprimer une adresse du compte n'affecte jamais une commande passée (le lien devient NULL à la
  suppression, la copie reste). Le téléphone est facultatif.

## 9. Délais de préparation et de livraison (décidé le 11 novembre 2026, chapitre 1.3)

Tout est **calculé en jours ouvrés** par `DelaisLivraison`, d'après `livraison.delais` (`config/services.yaml`) :

1. **Préparation : 48 h ouvrées** (2 jours ouvrés) à partir du jour de la commande. Une commande passée un samedi ou un
   dimanche compte comme passée le lundi suivant.
2. **Expédition : lundi, mardi ou mercredi** (produits frais : un colis ne doit pas passer le week-end en entrepôt). C'est le premier
   de ces jours, à partir de la fin de la préparation.
3. **Livraison Colissimo : 2 à 5 jours ouvrés** après l'expédition.
4. Les **jours de fermeture** (congés, jours fériés) ne comptent pas, ni pour la préparation ni pour l'expédition ni pour la livraison.

Exemple (novembre 2026) : commande le lundi 9 → expédiée mercredi 11 → « Livraison estimée entre le vendredi 13 novembre et le
mercredi 18 novembre » ; commande le mercredi 11 → expédiée lundi 16 → « entre le mercredi 18 et le lundi 23 novembre ».

**Réglages sans toucher au code** (variables d'environnement, ou `services.yaml`) :
- `LIVRAISON_JOURS_EXPEDITION` : jours d'expédition, 1 = lundi … 7 = dimanche (défaut `1,2,3`) ;
- `LIVRAISON_FERMETURES` : dates `AAAA-MM-JJ` ou plages `AAAA-MM-JJ/AAAA-MM-JJ`, séparées par des virgules
  (ex. `2026-12-24/2027-01-03,2026-12-25`).

**Où la période est affichée** (« Livraison estimée entre le [jour] et le [jour] ») : panier, modale du panier, page de paiement
(avant validation), e-mail de confirmation et espace client. La période annoncée au moment de la commande est **mémorisée sur la
commande** (`livraison_estimee_debut` / `fin`) : elle ne change plus ensuite, même si la configuration change. Les CGV, la FAQ de
l'accueil et le bandeau de la page d'accueil reprennent la même configuration. En retrait sur place, c'est le message de retrait qui
s'affiche (« Commande prête sous 24 h… »).

## 10. Expédition, suivi et colis retournés (décidé le 12 novembre 2026, chapitre 1.3)

- **Saisie admin** : transporteur (Colissimo par défaut) et numéro de suivi (6 à 30 lettres/chiffres, espaces retirés, majuscules).
- **Dates automatiques** : `date_expedition` au passage à « Expédiée », `date_livraison` au passage à « Livrée » ; jamais écrasées si déjà renseignées.
- **Lien de suivi** : modèle configurable `livraison.suivi_url` (`{numero}` remplacé) ; pas de lien pour un autre transporteur que celui configuré. Affiché dans le compte client et l'e-mail.
- **E-mail d'expédition** : envoyé une seule fois (`email_expedition_envoye_le`), uniquement en livraison à domicile, au passage à « Expédiée » depuis l'administration.
- **Retournée** : depuis « Expédiée » ; motif obligatoire (note inscrite dans l'historique). Ensuite : réexpédition (« En préparation »), remboursement ou annulation. Le remboursement reste fait dans Stripe ; le stock n'est jamais remis automatiquement.

## 11. TVA (décidé le 16 novembre 2026, chapitre 1.4)

- Société assujettie à la TVA. Un **taux par produit et par box** (`taux_tva`, 5,5 % par défaut, modifiable dans l'administration).
- **Les prix affichés sont TTC** et ne changent pas : le HT et la TVA sont **déduits** du TTC (`CalculTva`, calcul en centimes entiers).
- **Frais de livraison** : même taux que les produits ; si les taux sont mélangés, répartition **au prorata de la valeur HT** des articles de chaque taux (le dernier groupe reçoit le reliquat des centimes).
- HT et TVA sont calculés **sur le total de chaque taux** (pas ligne par ligne). HT + TVA = TTC, toujours, au centime.
- **Figé sur la commande** à sa création (au moment où le client va payer, comme les lignes et le montant Stripe) : `total_ht`, `total_tva`, `tva_detail` (par taux) et `ligne_commande.taux_tva`. Modifier un taux plus tard ne change jamais une commande existante. Les commandes antérieures au chapitre 1.4 n'ont pas ces champs (pas de facture pour elles).
- À faire valider par le comptable avant la mise en ligne : taux par produit et taux appliqué aux frais de livraison.
