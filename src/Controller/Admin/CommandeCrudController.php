<?php

namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Entity\CommandeHistorique;
use App\Service\ChangementStatutCommande;
use App\Service\EmailExpeditionService;
use App\Service\ContexteChangementStatut;
use App\Service\RestitutionStockService;
use App\Enum\CommandeStatut;
use App\Enum\ModeLivraison;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

class CommandeCrudController extends AbstractCrudController
{
    public function __construct(
        private ChangementStatutCommande $statuts,
        private ContexteChangementStatut $contexte,
        private RestitutionStockService $restitutionStock,
        private EmailExpeditionService $emailExpedition
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Commande::class;
    }

    public function configureFields(string $pageName): iterable
    {
        // Formulaire : les choix sont les cas de l'énumération (Symfony compare des objets, pas du texte : avec des
        // chaînes, la page d'édition plantait « Object of class CommandeStatut could not be converted to string »).
        // Liste et détail : valeurs texte et badges de couleur.
        if (in_array($pageName, [Crud::PAGE_EDIT, Crud::PAGE_NEW], true)) {
            // Seuls les statuts atteignables depuis le statut actuel sont proposés, et jamais « Payée » depuis « En attente » :
            // tous les paiements passent par Stripe (webhook ou commande de rattrapage), jamais à la main.
            $commande = $this->getContext()?->getEntity()->getInstance();
            $proposes = $commande instanceof Commande && $commande->getStatut() !== null
                ? [$commande->getStatut(), ...$this->statuts->statutsChoisissablesParAdmin($commande)]
                : CommandeStatut::cases();
            $statut = ChoiceField::new('statut', 'Statut')
                ->setChoices(array_combine(
                    array_map(static fn (CommandeStatut $s): string => $s->getLibelle(), $proposes),
                    $proposes
                ))
                ->setHelp('Seules les transitions prévues sont acceptées (voir docs/regles-metier.md). « Payée » (depuis « En attente »), « Remboursée » et « À vérifier » ne viennent que de Stripe (webhook ou rattrapage). Chaque changement est inscrit dans l\'historique.');
        } else {
            $statut = ChoiceField::new('statut', 'Statut')
                ->setChoices([
                    'En attente' => CommandeStatut::EN_ATTENTE->value,
                    'Payée' => CommandeStatut::PAYEE->value,
                    'Confirmée' => CommandeStatut::CONFIRMEE->value,
                    'En préparation' => CommandeStatut::EN_PREPARATION->value,
                    'Expédiée' => CommandeStatut::EXPEDIEE->value,
                    'Livrée' => CommandeStatut::LIVREE->value,
                    'Annulée' => CommandeStatut::ANNULEE->value,
                    'À vérifier' => CommandeStatut::A_VERIFIER->value,
                    'Remboursée' => CommandeStatut::REMBOURSEE->value,
                    'Retournée' => CommandeStatut::RETOURNEE->value,
                ])
                ->renderAsBadges([
                    CommandeStatut::EN_ATTENTE->value => 'warning',
                    CommandeStatut::PAYEE->value => 'success',
                    CommandeStatut::CONFIRMEE->value => 'info',
                    CommandeStatut::EN_PREPARATION->value => 'primary',
                    CommandeStatut::EXPEDIEE->value => 'secondary',
                    CommandeStatut::LIVREE->value => 'success',
                    CommandeStatut::ANNULEE->value => 'danger',
                    CommandeStatut::A_VERIFIER->value => 'danger',
                    CommandeStatut::REMBOURSEE->value => 'secondary',
                    CommandeStatut::RETOURNEE->value => 'warning',
                ]);
        }

        return [
            IdField::new('id')->hideOnForm(),
            
            TextField::new('numeroCommande', 'N° Commande'),
            
            // C'est "user" et non "utilisateur"
            AssociationField::new('user', 'Client'),
            
            $statut,
            
            // total_ttc est stocké en euros (pas en centimes) : pas de division par 100
            MoneyField::new('totalTTC', 'Total TTC')->setCurrency('EUR')->setStoredAsCents(false),
            
            ChoiceField::new('modeLivraison', 'Mode')
                ->setChoices([
                    'Livraison à domicile' => ModeLivraison::LIVRAISON,
                    'Retrait sur place' => ModeLivraison::RETRAIT,
                ]),

            TextField::new('adresseLivraisonTexte', 'Adresse de livraison (copie figée)')
                ->onlyOnDetail()
                ->formatValue(static fn ($valeur) => nl2br(htmlspecialchars((string) $valeur)))
                ->renderAsHtml(),

            TextField::new('transporteur', 'Transporteur')->setRequired(false)->hideOnIndex()
                ->setHelp('Rempli tout seul (Colissimo) au passage à « Expédiée » ; modifiable.'),
            TextField::new('numeroSuivi', 'Numéro de suivi')->setRequired(false)->hideOnIndex()
                ->setHelp('6 à 30 lettres/chiffres. Le lien de suivi apparaît dans le compte du client et l\'e-mail d\'expédition.'),
            DateTimeField::new('dateExpedition', 'Date d\'expédition')->hideOnIndex()->setFormTypeOption('disabled', true)
                ->setHelp('Automatique au passage à « Expédiée ».'),
            DateTimeField::new('dateLivraison', 'Date de livraison')->hideOnIndex()->setFormTypeOption('disabled', true)
                ->setHelp('Automatique au passage à « Livrée ».'),
            TextareaField::new('noteChangementStatut', 'Note du changement de statut')->onlyOnForms()->setRequired(false)
                ->setHelp('Obligatoire pour « Retournée » (motif du retour) et pour « À vérifier » → « Payée » (comment le paiement a été vérifié). Inscrite dans l\'historique.'),

            
            MoneyField::new('montantRembourse', 'Remboursé (Stripe)')->setCurrency('EUR')->setStoredAsCents(false)
                ->setFormTypeOption('disabled', true)
                ->setHelp('Renseigné automatiquement par Stripe (événement charge.refunded). Le remboursement se fait dans le tableau de bord Stripe.')
                ->hideOnIndex(),

            BooleanField::new('stockRetire', 'Stock retiré par le système')
                ->setFormTypeOption('disabled', true)
                ->hideOnIndex(),

            BooleanField::new('stockRestitue', 'Stock remis en vente')
                ->setHelp('À cocher UNIQUEMENT pour une commande annulée ou remboursée dont les produits sont encore vendables (denrées alimentaires : jamais automatique). Impossible à annuler.')
                ->hideOnIndex(),

            TextField::new('historiqueTexte', 'Historique des statuts')
                ->onlyOnDetail()
                ->formatValue(static fn ($valeur) => nl2br(htmlspecialchars((string) $valeur)))
                ->renderAsHtml(),

            CollectionField::new('lignesCommande', 'Lignes de commande')
                ->hideOnIndex()
                ->onlyOnDetail(),
        ];
    }

    /**
     * Une commande « À vérifier » (payée avec un écart) ne doit jamais pouvoir être supprimée :
     * le bouton est masqué ici, et deleteEntity() refuse aussi les requêtes forgées à la main.
     */
    public function configureActions(Actions $actions): Actions
    {
        $masquerSiAVerifier = static fn (Action $action): Action => $action->displayIf(
            static fn (Commande $commande): bool => $commande->getStatut() !== CommandeStatut::A_VERIFIER
        );

        return $actions
            ->update(Crud::PAGE_INDEX, Action::DELETE, $masquerSiAVerifier)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $masquerSiAVerifier);
    }

    /**
     * Le statut ne peut changer que selon le workflow (config/packages/workflow.yaml) : un changement interdit
     * est refusé avec la liste des statuts possibles. Chaque changement accepté est inscrit dans l'historique
     * (source « admin », avec l'e-mail de l'administrateur).
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Commande) {
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['statut'] ?? null;
            if (is_string($original)) {
                $original = CommandeStatut::tryFrom($original); // texte brut selon le chemin de chargement
            }
            $nouveau = $entityInstance->getStatut();

            // Jamais « Payée » à la main (requête forgée comprise) : seuls le webhook Stripe et la commande de rattrapage le font
            if ($original instanceof CommandeStatut && $nouveau !== null && $nouveau !== $original
                && $this->statuts->reserveAuSysteme($original, $nouveau)) {
                $entityInstance->setStatut($original);
                $this->addFlash('danger', sprintf(
                    'Changement refusé : le statut « %s » ne peut pas être posé à la main : paiements et remboursements passent par Stripe. Si un webhook a été manqué, utilisez la commande de rattrapage (php bin/console app:stripe-rejouer-evenement).',
                    $nouveau->getLibelle()
                ));

                return;
            }

            if ($original instanceof CommandeStatut && $nouveau !== null && $nouveau !== $original
                && !$this->statuts->peutAller($entityInstance, $original, $nouveau)) {
                $possibles = array_map(static fn (CommandeStatut $s) => $s->getLibelle(), $this->statutsDepuis($entityInstance, $original));
                $entityInstance->setStatut($original); // le changement interdit n'est pas enregistré

                $this->addFlash('danger', sprintf(
                    'Changement de statut refusé : « %s » → « %s ». Statuts possibles : %s.',
                    $original->getLibelle(),
                    $nouveau->getLibelle(),
                    $possibles === [] ? 'aucun (statut final)' : implode(', ', $possibles)
                ));

                return;
            }

            // Note obligatoire pour certains changements (« Retournée » : motif du retour ; « À vérifier » -> « Payée » : vérification du paiement)
            $note = trim((string) $entityInstance->getNoteChangementStatut());
            $messageNote = ($original instanceof CommandeStatut && $nouveau !== null) ? $this->statuts->noteObligatoire($original, $nouveau) : null;
            if ($messageNote !== null && $note === '') {
                $entityInstance->setStatut($original);
                $this->addFlash('danger', 'Changement refusé : ' . $messageNote);

                return;
            }

            // Annuler une commande payée n'annule pas le paiement : avertissement + trace dans l'historique
            $avertissement = ($original instanceof CommandeStatut && $nouveau !== null)
                ? $this->statuts->avertissementAnnulation($original, $nouveau)
                : null;
            if ($avertissement !== null) {
                $this->addFlash('warning', $avertissement);
            }

            $this->contexte->definir(CommandeHistorique::SOURCE_ADMIN, $this->getUser()?->getUserIdentifier(), trim($avertissement . ' ' . $note) ?: null);

            // Remise en stock : décision de l'administrateur (case à cocher), une seule fois, jamais défaite
            $dejaRestitue = (bool) ($entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['stockRestitue'] ?? false);
            if ($entityInstance->isStockRestitue() !== $dejaRestitue) {
                $entityInstance->setStockRestitue($dejaRestitue); // on repart de l'état enregistré, le service refait le changement s'il est permis
                if ($dejaRestitue) {
                    $this->addFlash('danger', 'Le stock a déjà été remis en vente : cette case ne peut pas être décochée.');
                } else {
                    try {
                        $this->restitutionStock->restituer($entityInstance, $this->getUser()?->getUserIdentifier());
                        $this->addFlash('success', 'Stock remis en vente.');
                    } catch (\DomainException $e) {
                        $this->addFlash('danger', 'Remise en stock refusée : ' . $e->getMessage());
                    }
                }
            }        }

        parent::updateEntity($entityManager, $entityInstance);

        // Passage à « Expédiée » : e-mail avec le lien de suivi (une seule fois, livraison à domicile)
        if ($entityInstance instanceof Commande && $entityInstance->getStatut() === CommandeStatut::EXPEDIEE && $this->emailExpedition->envoyer($entityInstance)) {
            $this->addFlash('success', 'E-mail d\'expédition envoyé au client.');
        }
    }

    /** @return list<CommandeStatut> les statuts atteignables depuis $depuis (le statut en mémoire a pu changer) */
    private function statutsDepuis(Commande $commande, CommandeStatut $depuis): array
    {
        $copie = (new Commande())->setStatut($depuis);

        return $this->statuts->statutsPossibles($copie);
    }

    /**
     * Point de passage unique de la suppression simple ET groupée (batch) dans EasyAdmin.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Commande && $entityInstance->getStatut() === CommandeStatut::A_VERIFIER) {
            $this->addFlash('danger', sprintf(
                'La commande %s est payée avec un écart : elle ne peut pas être supprimée.',
                $entityInstance->getNumeroCommande()
            ));

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }
}