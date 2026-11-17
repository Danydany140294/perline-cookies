<?php

namespace App\Controller\Admin;

use App\Entity\Produit;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\Validator\Constraints\Image;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ProduitCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Produit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cookie')
            ->setEntityLabelInPlural('Cookies')
            ->setSearchFields(['name', 'description']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Pas de suppression : un cookie vendu figure dans l'historique des commandes. On le DÉSACTIVE (interrupteur « Actif » de la liste
        // ou du formulaire) ; le journal garde la trace.
        return $actions->disable(Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
            // Prix saisis TTC, en euros (pas en centimes)
            MoneyField::new('prix', 'Prix TTC')->setCurrency('EUR')->setStoredAsCents(false),
            NumberField::new('tauxTva', 'Taux de TVA (%)')->setNumDecimals(2)->hideOnIndex()
                ->setHelp('5,5 % par défaut. À faire valider par votre comptable.'),
            IntegerField::new('stock', 'Stock'),
            IntegerField::new('seuilAlerte', 'Seuil d\'alerte de stock')->hideOnIndex()->setRequired(false)
                ->setHelp('Facultatif : vide = seuil global (config/boutique.yaml). Un e-mail est envoyé une seule fois quand le stock passe sous ce seuil.'),
            ImageField::new('image', 'Image')
                ->setBasePath('asset/images/produits')
                ->setUploadDir('public/asset/images/produits')
                ->setUploadedFileNamePattern('[randomhash].[extension]') // nom ALÉATOIRE : jamais le nom du fichier envoyé
                ->setFileConstraints(new Image(
                    maxSize: '2M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxSizeMessage: 'L\'image est trop lourde ({{ size }} {{ suffix }}) : maximum {{ limit }} {{ suffix }}.',
                    mimeTypesMessage: 'Format d\'image non autorisé : JPEG, PNG ou WebP uniquement.',
                ))
                ->setFormTypeOption('allow_delete', false)
                ->setHelp('JPEG, PNG ou WebP, 2 Mo maximum. Le fichier est renommé automatiquement.')
                ->hideOnIndex(),
            TextField::new('messageDisponibilite', 'Disponibilité')->onlyOnIndex(),
            BooleanField::new('isActive', 'Actif')
                ->setHelp('Décoché : le cookie n\'apparaît plus sur le site et ne peut plus être commandé. Un cookie en rupture (stock à 0) est signalé automatiquement, sans toucher à ce réglage.'),
        ];
    }
}
