<?php

namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Entity\CommandeHistorique;
use App\Service\ChangementStatutCommande;
use App\Service\ContexteChangementStatut;
use App\Enum\CommandeStatut;
use App\Enum\ModeLivraison;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

class CommandeCrudController extends AbstractCrudController
{
    public function __construct(
        private ChangementStatutCommande $statuts,
        private ContexteChangementStatut $contexte
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Commande::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            
            TextField::new('numeroCommande', 'N° Commande'),
            
            // C'est "user" et non "utilisateur"
            AssociationField::new('user', 'Client'),
            
            ChoiceField::new('statut', 'Statut')
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
                ]),
            
            // total_ttc est stocké en euros (pas en centimes) : pas de division par 100
            MoneyField::new('totalTTC', 'Total TTC')->setCurrency('EUR')->setStoredAsCents(false),
            
            ChoiceField::new('modeLivraison', 'Mode')
                ->setChoices([
                    'Livraison à domicile' => ModeLivraison::LIVRAISON,
                    'Retrait sur place' => ModeLivraison::RETRAIT,
                ]),

            AssociationField::new('adresseLivraison', 'Adresse de livraison')->hideOnIndex(),

            DateTimeField::new('dateCommande', 'Date de commande'),
            
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
            $nouveau = $entityInstance->getStatut();

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

            $this->contexte->definir(CommandeHistorique::SOURCE_ADMIN, $this->getUser()?->getUserIdentifier());
        }

        parent::updateEntity($entityManager, $entityInstance);
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