<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Utilisateurs : réservé à l'administrateur. Ni mot de passe, ni rôles modifiables (sauf « gestionnaire »), ni création, ni suppression. */
class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission('ROLE_ADMIN')->setSearchFields(['email', 'nom', 'prenom']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Les comptes se créent par l'inscription ; l'administrateur (ROLE_ADMIN) ne s'attribue que par `app:promote-admin`.
        // La suppression est bloquée avec un message (voir deleteEntity) : un client se supprime lui-même par anonymisation (RGPD).
        return $actions->disable(Action::NEW)->setPermissions([Action::DELETE => 'ROLE_ADMIN', Action::BATCH_DELETE => 'ROLE_ADMIN']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            EmailField::new('email'),
            // Rôles : affichage seulement. Seul « gestionnaire » se donne / se retire ici (trace dans le journal).
            ArrayField::new('roles', 'Rôles')->hideOnForm(),
            BooleanField::new('gestionnaire', 'Gestionnaire (commandes, stock, catalogue)')
                ->setHelp('Peut gérer les commandes, le stock et le catalogue ; pas les utilisateurs, ni les rôles, ni les exports, ni les suppressions.'),
            TextField::new('nom'),
            TextField::new('prenom'),
            TextField::new('telephone'),
        ];
    }

    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Jamais de suppression ni d'erreur SQL brute : le compte porte des commandes et des factures conservées 10 ans.
        $this->addFlash('danger', sprintf(
            'Le compte %s ne peut pas être supprimé depuis l\'administration : ses commandes et factures sont conservées 10 ans. Le client peut supprimer lui-même son compte (anonymisation) depuis « Mon compte ».',
            $entityInstance instanceof User ? $entityInstance->getEmail() : ''
        ));
    }
}
