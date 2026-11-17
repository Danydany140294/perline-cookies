<?php

namespace App\Controller\Admin;

use App\Entity\LigneCommande;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Lignes de commande : HISTORIQUE, lecture seule (le prix, la TVA et la composition des boxes sont figés au moment de la commande). */
class LigneCommandeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LigneCommande::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id'),
            AssociationField::new('commande', 'Commande'),
            AssociationField::new('produit', 'Produit'),
            AssociationField::new('box', 'Box'),
            TextField::new('compositionTexte', 'Composition figée')
                ->formatValue(static fn ($v) => nl2br(htmlspecialchars((string) $v)))->renderAsHtml(),
            IntegerField::new('quantite', 'Quantité'),
            // Montants stockés en euros (pas en centimes) : pas de division par 100
            MoneyField::new('prixUnitaire', 'Prix unitaire TTC')->setCurrency('EUR')->setStoredAsCents(false),
            NumberField::new('tauxTva', 'TVA (%)')->setNumDecimals(2),
            MoneyField::new('sousTotal', 'Sous-total TTC')->setCurrency('EUR')->setStoredAsCents(false),
        ];
    }
}
