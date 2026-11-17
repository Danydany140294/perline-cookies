<?php

namespace App\Controller\Admin;

use App\Entity\JournalAdmin;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Journal des actions administratives : lecture seule, réservé à l'administrateur. */
class JournalAdminCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return JournalAdmin::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('ROLE_ADMIN')
            ->setEntityLabelInSingular('Action')
            ->setEntityLabelInPlural('Journal des actions')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['auteur', 'action', 'entite', 'libelle']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        $multiligne = static fn ($v) => nl2br(htmlspecialchars((string) $v));

        return [
            IdField::new('id'),
            DateTimeField::new('dateAction', 'Date'),
            TextField::new('auteur', 'Auteur'),
            TextField::new('action', 'Action'),
            TextField::new('libelle', 'Élément'),
            TextField::new('resume', 'Changements')->formatValue($multiligne)->renderAsHtml(),
        ];
    }
}
