<?php

namespace App\Controller\Admin;

use App\Entity\Box;
use App\Form\BoxProduitType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\Validator\Constraints\Image;
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
        // Pas de suppression : une box vendue figure dans l'historique des commandes. On la DÉSACTIVE (interrupteur « Active » de la liste
        // ou du formulaire) ; le journal garde la trace.
        return $actions->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::DELETE, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        // Le « stock » de la box n'est plus saisi : il se déduit des stocks de ses cookies.
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('nom', 'Nom'),
            TextField::new('description', 'Description'),
            ImageField::new('image', 'Image')
                ->setBasePath('asset/images/boxs')
                ->setUploadDir('public/asset/images/boxs')
                ->setUploadedFileNamePattern('[randomhash].[extension]') // nom ALÉATOIRE : jamais le nom du fichier envoyé
                ->setFileConstraints(new Image(
                    maxSize: '2M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxSizeMessage: 'L\'image est trop lourde ({{ size }} {{ suffix }}) : maximum {{ limit }} {{ suffix }}.',
                    mimeTypesMessage: 'Format d\'image non autorisé : JPEG, PNG ou WebP uniquement.',
                ))
                ->setFormTypeOption('allow_delete', false)
                ->setRequired($pageName === \EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_NEW) // à la modification, on peut garder l'image actuelle
                ->setHelp('JPEG, PNG ou WebP, 2 Mo maximum. Le fichier est renommé automatiquement.')
                ->hideOnIndex(),
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
            NumberField::new('tauxTva', 'Taux de TVA (%)')->setNumDecimals(2)->hideOnIndex()->setPermission('ROLE_ADMIN')
                ->setHelp('5,5 % par défaut. À faire valider par votre comptable.'),
            BooleanField::new('isActive', 'Active'),
            IntegerField::new('stockDisponible', 'Stock (calculé)')->onlyOnIndex(),
            TextField::new('statutDisponibilite', 'Disponibilité')->onlyOnIndex(),
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
