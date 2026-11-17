<?php

namespace App\Controller\Admin;

use App\Entity\Box;
use App\Form\BoxProduitType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BoxCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Box::class;
    }

    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        // Suppression : réservée à l'administrateur (le gestionnaire gère commandes, stock et catalogue, sans suppressions)
        return $actions->setPermissions([\EasyCorp\Bundle\EasyAdminBundle\Config\Action::DELETE => 'ROLE_ADMIN', \EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE => 'ROLE_ADMIN']);
    }

    public function configureFields(string $pageName): iterable
    {
        // Le « stock » de la box n'est plus saisi : il se déduit des stocks de ses cookies.
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('nom', 'Nom'),
            TextField::new('description', 'Description'),
            TextField::new('image', 'Image (nom du fichier)'),
            ChoiceField::new('type', 'Type')->setChoices([
                'Fixe (recette ci-dessous)' => 'fixe',
                'Personnalisable' => 'personnalisable',
            ]),
            MoneyField::new('prix', 'Prix')->setCurrency('EUR')->setStoredAsCents(false)
                ->setHelp('Prix des boxes classiques. IGNORÉ pour la box personnalisable : ses prix sont ceux des trois tailles ci-dessous.'),
            IntegerField::new('taille', 'Taille')->hideOnIndex(),
            MoneyField::new('prix6', 'Prix (6 cookies)')->setCurrency('EUR')->setStoredAsCents(false)->hideOnIndex()
                ->setHelp('Box personnalisable : SEULE source du prix facturé et affiché pour cette taille (obligatoire, supérieur à 0).'),
            MoneyField::new('prix12', 'Prix (12 cookies)')->setCurrency('EUR')->setStoredAsCents(false)->hideOnIndex(),
            MoneyField::new('prix24', 'Prix (24 cookies)')->setCurrency('EUR')->setStoredAsCents(false)->hideOnIndex(),
            NumberField::new('tauxTva', 'Taux de TVA (%)')->setNumDecimals(2)->hideOnIndex()
                ->setHelp('5,5 % par défaut. À faire valider par votre comptable.'),
            BooleanField::new('isActive', 'Active'),
            IntegerField::new('stockDisponible', 'Stock (calculé)')->onlyOnIndex(),
            CollectionField::new('compositions', 'Recette : cookies et quantités')
                ->setEntryType(BoxProduitType::class)
                ->setFormTypeOption('by_reference', false)
                ->allowAdd()
                ->allowDelete()
                ->setEntryIsComplex(false)
                ->hideOnIndex(),
        ];
    }
}
