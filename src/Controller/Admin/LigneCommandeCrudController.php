<?php

namespace App\Controller\Admin;

use App\Entity\LigneCommande;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class LigneCommandeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LigneCommande::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            AssociationField::new('commande', 'Commande'),
            AssociationField::new('produit', 'Produit'),
            AssociationField::new('box', 'Box'),

            IntegerField::new('quantite', 'Quantité'),

            // Montants stockés en euros (pas en centimes) : pas de division par 100
            MoneyField::new('prixUnitaire', 'Prix unitaire')
                ->setCurrency('EUR')
                ->setStoredAsCents(false),

            // Calculé (quantité × prix unitaire) : affichage seulement
            MoneyField::new('sousTotal', 'Sous-total')
                ->setCurrency('EUR')
                ->setStoredAsCents(false)
                ->hideOnForm(),
        ];
    }
}
