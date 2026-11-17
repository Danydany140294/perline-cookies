<?php

namespace App\Form;

use App\Service\ParametresBoutique as P;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Formulaire de l'écran « Paramètres de la boutique » : un champ par entrée de ParametresBoutique::CHAMPS (aucun secret). */
class ParametresBoutiqueType extends AbstractType
{
    /** « boutique.nom_marque » devient « boutique__nom_marque » (le point est interdit dans un nom de champ). */
    public static function nomChamp(string $cle): string
    {
        return str_replace('.', '__', $cle);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (P::CHAMPS as $cle => $champ) {
            $requis = $champ['requis'] ?? false;
            $contraintes = $requis && $champ['type'] !== P::TYPE_BOOLEEN ? [new Assert\NotBlank(message: 'Ce champ est obligatoire.')] : [];
            $options = [
                'label' => $champ['label'],
                'required' => $requis && $champ['type'] !== P::TYPE_BOOLEEN,
                'help' => $champ['aide'] ?? null,
                'attr' => ['data-groupe' => $champ['groupe']],
                'constraints' => $contraintes,
            ];

            switch ($champ['type']) {
                case P::TYPE_BOOLEEN:
                    $builder->add(self::nomChamp($cle), CheckboxType::class, ['required' => false] + $options);
                    break;
                case P::TYPE_ENTIER:
                    $options['constraints'][] = new Assert\PositiveOrZero(message: 'Le nombre doit être positif ou nul.');
                    $builder->add(self::nomChamp($cle), IntegerType::class, $options);
                    break;
                case P::TYPE_EUROS:
                    $options['constraints'][] = new Assert\PositiveOrZero(message: 'Le montant doit être positif ou nul.');
                    $builder->add(self::nomChamp($cle), MoneyType::class, ['currency' => 'EUR', 'divisor' => 100] + $options);
                    break;
                case P::TYPE_EMAIL:
                    $options['constraints'][] = new Assert\Email(message: 'Adresse e-mail invalide.');
                    $builder->add(self::nomChamp($cle), EmailType::class, $options);
                    break;
                case P::TYPE_LONG:
                    $builder->add(self::nomChamp($cle), TextareaType::class, $options);
                    break;
                case P::TYPE_JOURS:
                    $options['constraints'][] = new Assert\All([new Assert\Regex(pattern: '/^[1-7]$/', message: 'Jours de 1 (lundi) à 7 (dimanche), séparés par des virgules.')]);
                    $builder->add(self::nomChamp($cle), TextType::class, $options);
                    $this->listeEnTexte($builder, self::nomChamp($cle));
                    break;
                case P::TYPE_DATES:
                    $date = '\d{4}-\d{2}-\d{2}';
                    $options['constraints'][] = new Assert\All([new Assert\Regex(pattern: "/^$date(\/$date)?$/", message: 'Dates AAAA-MM-JJ ou plages AAAA-MM-JJ/AAAA-MM-JJ, séparées par des virgules.')]);
                    $builder->add(self::nomChamp($cle), TextType::class, $options);
                    $this->listeEnTexte($builder, self::nomChamp($cle));
                    break;
                case P::TYPE_URL_SUIVI:
                    $options['constraints'][] = new Assert\Regex(pattern: '~^https://[^\s{}]+\{numero\}[^\s{}]*$~', message: 'Adresse https contenant {numero}.');
                    $builder->add(self::nomChamp($cle), TextType::class, $options);
                    break;
                default:
                    $options['constraints'][] = new Assert\Length(max: 255, maxMessage: '255 caractères au maximum.');
                    $builder->add(self::nomChamp($cle), TextType::class, $options);
            }
        }
    }

    /** Liste de textes <-> « a, b, c » dans le formulaire. */
    private function listeEnTexte(FormBuilderInterface $builder, string $nom): void
    {
        $builder->get($nom)->addModelTransformer(new \Symfony\Component\Form\CallbackTransformer(
            static fn (mixed $liste): string => implode(', ', (array) $liste),
            static fn (?string $texte): array => P::normaliser(P::TYPE_JOURS, (string) $texte),
        ));
    }
}
