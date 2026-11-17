# Installer la boutique pour un nouveau client (marque blanche)

Principe : **le code ne change pas d'un client à l'autre**. On change uniquement les **paramètres** (écran « Paramètres de la boutique » de l'administration), les **produits**, le **logo / la charte** et les **secrets** (variables d'environnement). Ce guide est la liste des étapes, dans l'ordre. Pour le déploiement technique, voir `docs/MISE-EN-LIGNE.md`.

> Ce que le projet contient encore de « technique » ne se voit pas côté client : le nom de la base de données (`creaself`), l'espace de noms PHP `App\` et les noms de conteneurs de développement ne sont pas renommés (aucun impact visible). Aucune mention « CreaSelf » n'apparaît sur le site, dans l'administration, les e-mails ou les PDF : tout vient des paramètres.

## 1. Préparer le client (avant de toucher à la machine)

- [ ] Raison sociale, forme juridique, capital, SIRET, RCS, numéro de TVA intracommunautaire, adresse du siège.
- [ ] E-mail de contact, e-mail d'expédition des messages (`noreply@…` sur le domaine du client), téléphone, directeur de publication, médiateur de la consommation.
- [ ] Nom de domaine et accès à son DNS ; service d'envoi d'e-mails (SMTP/API) avec SPF/DKIM configurés pour le domaine.
- [ ] Compte Stripe du client (activation du mode live terminée).
- [ ] Logo, couleurs, polices (la mise en variables de la charte est prévue au chapitre « front » : en attendant, le logo et les couleurs se changent dans les fichiers de style).
- [ ] Catalogue : noms, descriptions, photos (JPEG/PNG/WebP, 2 Mo maximum), prix **TTC**, taux de TVA, stocks.

## 2. Installer et configurer

1. Déployer le code et les variables d'environnement (`docs/MISE-EN-LIGNE.md`, §1) avec **`BOUTIQUE_MODE_DEMO=1` au début** : tant que tout n'est pas validé, le bandeau et le filigrane « démonstration » protègent contre une facture sans valeur.
2. Créer la base, jouer les migrations (après sauvegarde, §2 de la mise en ligne). Ne **pas** charger les fixtures de démonstration en production.
3. Créer le premier compte (inscription sur le site), le vérifier par e-mail, puis le promouvoir administrateur : `php bin/console app:promote-admin <email>`.
4. Se connecter à `/admin` et ouvrir **« Paramètres de la boutique »** :
   - identité légale (raison sociale, SIRET, RCS, TVA, adresse, nom de la marque, site web) ;
   - contact (e-mail de contact, e-mail d'expédition, nom d'expéditeur, téléphone) ;
   - **e-mail d'alertes** (reçoit « commande payée » et « stock faible » ; vide = e-mail de contact) ;
   - **retrait** (lieu, adresse, horaires, message) ;
   - **livraison** (frais de livraison, seuil de gratuité, jours d'expédition, fermetures) ;
   - **seuil de stock** (alerte à partir de ce stock, seuil global) ;
   - **fonctions optionnelles** : favoris, avis, fidélité, retrait sur place, livraison à domicile (activer seulement ce que le client veut, rien n'est supprimé) ;
   - **pages légales** : hébergeur réel (raison sociale, adresse, pays où sont stockées les données), médiateur de la consommation (nom, adresse, site : obligatoire pour vendre à des particuliers), service d'envoi d'e-mails (sous-traitant), horaires du service client, traces d'allergènes de l'atelier, date de mise à jour ;
   - **mode démo** : laisser actif jusqu'à la validation finale (voir §5).
   Chaque modification est tracée dans le **journal des actions**. Les clés Stripe et les identifiants d'e-mail **ne se saisissent jamais ici** : ils restent dans les variables d'environnement.
5. Supprimer ou désactiver les cookies et boxes d'exemple, puis créer ceux du client : prix TTC, **taux de TVA** (réservé à l'administrateur), stock, seuil d'alerte facultatif, image.
6. Vérifier la **composition** de chaque box classique (quantités de cookies) et la **box personnalisable** (prix des 3 tailles).

## 3. Stripe et webhook

1. Renseigner `STRIPE_PUBLIC_KEY` et `STRIPE_SECRET_KEY` (clés **live**) côté hébergeur.
2. Créer le point d'entrée de webhook (`https://<domaine>/webhook/stripe`, événements `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`) et renseigner `STRIPE_WEBHOOK_SECRET` (détail : `docs/MISE-EN-LIGNE.md`, §5).
3. Paiement réel de faible montant, puis remboursement : la commande passe « Payée », la facture et l'e-mail partent, l'avoir est émis au remboursement.

## 4. Validations obligatoires par des professionnels

- [ ] **Comptable** : taux de TVA des produits et des frais de livraison, mentions de facture, régime de TVA du client.
- [ ] **Avocat, OBLIGATOIRE avant une vraie ouverture** : relecture des 4 pages légales (CGV, mentions légales, politique de confidentialité, gestion des cookies). Les textes fournis sont des **modèles** alimentés par les paramètres de la boutique et vérifiés contre ce que fait le site, mais ils ne constituent **pas un conseil juridique** : exclusion du droit de rétractation pour les denrées périssables, produit abîmé ou non conforme, durées de conservation, sous-traitants, médiateur de la consommation. Mettre à jour le paramètre « Date de dernière mise à jour des pages légales » après chaque relecture.
- [ ] **Allergènes** : renseigner, pour **chaque cookie**, les 14 allergènes réglementaires (administration, fiche du cookie). Obligatoire pour la vente de denrées alimentaires à distance ; tant qu'ils ne le sont pas, le site invite le client à contacter la boutique.
- [ ] Le client confirme qu'il conserve ses **factures 10 ans** (base + dossier des PDF sauvegardés).

## 5. Passage en production

1. **Supprimer toutes les données de test** : commandes (dont celles sans client), factures de test, paniers, adresses, avis, comptes de test et le compte administrateur de démonstration (jamais en production). Vérifier stocks, prix et TVA.
2. Repartir d'une numérotation de factures propre : les factures de test ne doivent pas consommer la séquence réelle (base de production neuve, ou nettoyage validé par le comptable avant la première vraie vente).
3. **Désactiver le mode démo** : paramètre « Mode démo » décoché dans l'écran des paramètres, et variable `BOUTIQUE_MODE_DEMO=0` côté hébergeur. Vérifier que le bandeau a disparu et qu'une facture n'a plus de filigrane.
4. Vérifier : `https` actif, e-mails (vérification de compte, confirmation de commande, alerte « commande payée ») reçus, planifications actives (nettoyages, factures manquantes), sauvegardes testées (`docs/MISE-EN-LIGNE.md`).

## 6. Ce qui reste hors paramètres (pour mémoire)

- Logo, couleurs, polices : chapitre « front » (variables de charte).
- Textes éditoriaux du site (accueil, « à propos ») : à rédiger avec le client.
- Miniatures et redimensionnement automatique des images : prévus au chapitre « front ».
