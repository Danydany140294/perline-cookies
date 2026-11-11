<?php

namespace App\Form;

use App\Entity\Adresse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Service\ZoneLivraison;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class AdresseType extends AbstractType
{
    public function __construct(private ZoneLivraison $zone)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom de l\'adresse',
                'attr' => [
                    'placeholder' => 'Ex: Domicile, Bureau...',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Veuillez saisir un nom pour cette adresse'
                    ]),
                    new Assert\Length([
                        'min' => 2,
                        'max' => 100,
                        'minMessage' => 'Le nom doit contenir au moins {{ limit }} caractères',
                        'maxMessage' => 'Le nom ne peut pas dépasser {{ limit }} caractères'
                    ])
                ]
            ])
            ->add('rue', TextType::class, [
                'label' => 'Adresse',
                'attr' => [
                    'placeholder' => 'Numéro et nom de rue',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Veuillez saisir l\'adresse'
                    ])
                ]
            ])
            ->add('complement', TextType::class, [
                'label' => 'Complément d\'adresse',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Bâtiment, appartement... (optionnel)',
                    'class' => 'form-control'
                ]
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'attr' => [
                    'placeholder' => '75001',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Veuillez saisir le code postal'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[0-9]{5}$/',
                        'message' => 'Le code postal doit contenir 5 chiffres'
                    ]),
                    // Zone desservie : message clair si le code postal est en Corse, outre-mer, etc.
                    new Assert\Callback(function (?string $valeur, ExecutionContextInterface $context): void {
                        if ($valeur !== null && preg_match('/^[0-9]{5}$/', $valeur) && ($raison = $this->zone->raisonCodePostal($valeur)) !== null) {
                            $context->buildViolation('Cette adresse n\'est pas desservie : ' . $raison)->addViolation();
                        }
                    }),
                ]
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'attr' => [
                    'placeholder' => 'Paris',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Veuillez saisir la ville'
                    ])
                ]
            ])
            // Pays figé : nous ne livrons qu'en France métropolitaine pour le moment (voir ZoneLivraison)
            ->add('pays', ChoiceType::class, [
                'label' => 'Pays',
                'choices' => [$this->zone->pays() => $this->zone->pays()],
                'attr' => ['class' => 'form-control'],
                'data' => $this->zone->pays(),
            ])            ->add('telephone', TextType::class, [
                'label' => 'Téléphone (facultatif)',
                'required' => false,
                'help' => 'Utile au transporteur pour vous prévenir de la livraison.',
                'attr' => [
                    'placeholder' => '06 12 34 56 78',
                    'autocomplete' => 'tel',
                    'class' => 'form-control',
                ],
            ])
            ->add('parDefaut', CheckboxType::class, [
                'label' => 'Définir comme adresse par défaut',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Adresse::class,
        ]);
    }
}