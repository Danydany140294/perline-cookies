<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de contact : données sous forme de tableau (pas d'entité, rien n'est enregistré en base).
 * La protection CSRF est active par défaut (jeton « contact »).
 */
class ContactType extends AbstractType
{
    public const SUJETS = [
        'Question sur une Box' => 'question',
        'Partenariat Pro' => 'pro',
        'Autre Douceur' => 'autre',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Votre Nom',
                'constraints' => [
                    new Assert\NotBlank(message: 'Merci d\'indiquer votre nom.'),
                    new Assert\Length(min: 2, max: 100, minMessage: 'Votre nom est trop court (2 caractères minimum).', maxMessage: 'Votre nom est trop long (100 caractères maximum).'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Votre Email',
                'constraints' => [
                    new Assert\NotBlank(message: 'Merci d\'indiquer votre adresse e-mail.'),
                    new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.', mode: Assert\Email::VALIDATION_MODE_HTML5),
                    new Assert\Length(max: 180, maxMessage: 'Cette adresse e-mail est trop longue.'),
                ],
            ])
            ->add('sujet', ChoiceType::class, [
                'label' => 'Sujet',
                'choices' => self::SUJETS,
                'placeholder' => '',
                'constraints' => [new Assert\NotBlank(message: 'Merci de choisir un sujet.')],
                'invalid_message' => 'Ce sujet n\'est pas valide.',
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Votre Message',
                'attr' => ['rows' => 4],
                'constraints' => [
                    new Assert\NotBlank(message: 'Merci d\'écrire votre message.'),
                    new Assert\Length(min: 10, max: 5000, minMessage: 'Votre message est trop court (10 caractères minimum).', maxMessage: 'Votre message est trop long (5000 caractères maximum).'),
                ],
            ])
            // Champ piège (anti-spam) : invisible pour un humain, rempli par beaucoup de robots.
            ->add('site_web', TextType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off', 'aria-hidden' => 'true'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'contact',
        ]);
    }
}
