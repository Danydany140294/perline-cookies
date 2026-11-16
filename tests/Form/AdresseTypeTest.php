<?php

namespace App\Tests\Form;

use App\Entity\Adresse;
use App\Form\AdresseType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Formulaire d'adresse : pays figé sur France, code postal dans la zone desservie, message clair sinon. */
class AdresseTypeTest extends KernelTestCase
{
    private function soumettre(array $donnees): \Symfony\Component\Form\FormInterface
    {
        self::bootKernel();
        $form = self::getContainer()->get('form.factory')->create(AdresseType::class, new Adresse(), ['csrf_protection' => false]);
        $form->submit($donnees + ['nom' => 'Domicile', 'rue' => '12 rue des Tests', 'codePostal' => '34000', 'ville' => 'Montpellier', 'pays' => 'France']);

        return $form;
    }

    public function testAdresseDansLaZoneEstValide(): void
    {
        $this->assertTrue($this->soumettre([])->isValid());
        $this->assertTrue($this->soumettre(['codePostal' => '75001', 'ville' => 'Paris'])->isValid());
    }

    public function testCorseEtOutreMerSontRefusesAvecUnMessageClair(): void
    {
        foreach (['20000' => 'Corse', '20200' => 'Corse', '97100' => 'outre-mer', '98000' => 'outre-mer'] as $cp => $mot) {
            $form = $this->soumettre(['codePostal' => $cp]);

            $this->assertFalse($form->isValid(), $cp);
            $messages = array_map(static fn ($e) => $e->getMessage(), iterator_to_array($form->get('codePostal')->getErrors()));
            $this->assertStringContainsString('Cette adresse n\'est pas desservie', implode(' ', $messages));
            $this->assertStringContainsString($mot, implode(' ', $messages));
        }
    }

    public function testLePaysEstFigeSurFrance(): void
    {
        $this->assertFalse($this->soumettre(['pays' => 'Belgique'])->isValid(), 'Belgique refusée');
        $this->assertFalse($this->soumettre(['pays' => 'Autre'])->isValid());
        self::bootKernel();
        $choix = self::getContainer()->get('form.factory')->create(AdresseType::class, new Adresse())->get('pays')->getConfig()->getOption('choices');
        $this->assertSame(['France' => 'France'], $choix);
    }

    public function testLeTelephoneEstFacultatifMaisControle(): void
    {
        $this->assertTrue($this->soumettre(['telephone' => ''])->isValid());
        $this->assertTrue($this->soumettre(['telephone' => '+33 6 12 34 56 78'])->isValid());
        $this->assertFalse($this->soumettre(['telephone' => 'abc'])->isValid());
    }
}
