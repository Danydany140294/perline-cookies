<?php

namespace App\Controller\Admin;

use App\Entity\Commande;
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