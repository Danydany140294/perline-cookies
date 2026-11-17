<?php

namespace App\Controller\Admin;

use App\Entity\Panier;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PanierCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Panier::class;
    }

    /*
    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return $crud->setEntityPermission('ROLE_ADMIN'); // paniers des clients : réservés à l'administrateur
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id'),
            TextField::new('title'),
            TextEditorField::new('description'),
        ];
    }
    */
}
