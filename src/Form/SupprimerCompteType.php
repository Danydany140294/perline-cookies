<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Confirmation de la suppression du compte par le mot de passe. */
class SupprimerCompteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motDePasse', PasswordType::class, [
            'label' => 'Confirmez avec votre mot de passe',
            'mapped' => false,
            'attr' => ['autocomplete' => 'current-password'],
            'constraints' => [new NotBlank(message: 'Veuillez saisir votre mot de passe.'), new UserPassword(message: 'Le mot de passe est incorrect.')],
        ]);
    }
}
