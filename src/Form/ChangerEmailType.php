<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Changement d'adresse e-mail depuis le compte : mot de passe demandé, nouvelle adresse confirmée par un lien. */
class ChangerEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nouvelEmail', EmailType::class, [
                'label' => 'Nouvelle adresse e-mail',
                'mapped' => false,
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [new NotBlank(message: 'Veuillez saisir la nouvelle adresse.'), new Email(message: 'Adresse e-mail invalide.'), new Length(max: 180)],
            ])
            ->add('motDePasseActuel', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new NotBlank(message: 'Veuillez saisir votre mot de passe.'), new UserPassword(message: 'Le mot de passe est incorrect.')],
            ]);
    }
}
