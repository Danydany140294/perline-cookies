<?php

namespace App\Admin\Filter;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\TextFilterType;

/** Filtre « client » des commandes : le texte saisi est cherché dans le nom, le prénom et l'adresse e-mail du client. */
final class ClientFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName = 'user', string $label = 'Client (nom ou e-mail)'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(TextFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $alias = $filterDataDto->getEntityAlias();
        $parametre = $filterDataDto->getParameterName();
        $texte = trim((string) $filterDataDto->getValue(), '% ');
        if ($texte === '') {
            return;
        }

        $queryBuilder
            ->join($alias . '.user', 'filtre_client')
            ->andWhere(sprintf('(filtre_client.email LIKE :%1$s OR filtre_client.nom LIKE :%1$s OR filtre_client.prenom LIKE :%1$s)', $parametre))
            ->setParameter($parametre, '%' . $texte . '%');
    }
}
