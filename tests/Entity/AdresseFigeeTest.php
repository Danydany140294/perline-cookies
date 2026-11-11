<?php

namespace App\Tests\Entity;

use App\Entity\Adresse;
use App\Entity\Commande;
use App\Entity\User;
use App\Enum\ModeLivraison;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/** Adresse de livraison copiée dans la commande, téléphone facultatif, détail des frais. */
class AdresseFigeeTest extends TestCase
{
    private function adresse(): Adresse
    {
        return (new Adresse())->setNom('Domicile')->setRue('12 rue des Tests')->setComplement('Bat. B')
            ->setCodePostal('34000')->setVille('Montpellier')->setPays('France')->setTelephone('06 12 34 56 78');
    }

    public function testLaCommandeGardeSaCopieQuandLAdresseDuCompteChange(): void
    {
        $adresse = $this->adresse();
        $commande = (new Commande())->figerAdresse($adresse, 'Camille Martin', '06 12 34 56 78');

        $adresse->setRue('99 avenue Changée')->setVille('Lyon')->setCodePostal('69001');

        $figee = $commande->getAdresseLivraisonFigee();
        $this->assertSame('12 rue des Tests', $figee['rue']);
        $this->assertSame('Montpellier', $figee['ville']);
        $this->assertSame('34000', $figee['codePostal']);
        $this->assertSame('Camille Martin', $figee['destinataire']);
        $this->assertSame('Bat. B', $figee['complement']);
        $this->assertSame('France', $figee['pays']);
        $this->assertSame('06 12 34 56 78', $figee['telephone']);
    }

    public function testLaCopieSurvitALaSuppressionDuLien(): void
    {
        $commande = (new Commande())->figerAdresse($this->adresse(), 'Camille Martin');

        $commande->setAdresseLivraison(null); // ce que fait la base (ON DELETE SET NULL) quand l'adresse est supprimée

        $this->assertTrue($commande->hasAdresseFigee());
        $this->assertSame('12 rue des Tests', $commande->getAdresseLivraisonFigee()['rue']);
        $this->assertStringContainsString("Camille Martin\n12 rue des Tests\nBat. B\n34000 Montpellier\nFrance", $commande->getAdresseLivraisonTexte());
    }

    public function testAncienneCommandeSansCopieUtiliseLAdresseDuCompteSiElleExiste(): void
    {
        $user = (new User())->setPrenom('Léa')->setNom('Dupont');
        $commande = (new Commande())->setUser($user)->setAdresseLivraison($this->adresse());

        $this->assertFalse($commande->hasAdresseFigee());
        $this->assertSame('12 rue des Tests', $commande->getAdresseLivraisonFigee()['rue']);
        $this->assertSame('Léa Dupont', $commande->getAdresseLivraisonFigee()['destinataire']);
    }

    public function testRetraitOuSansAdresseDonneNull(): void
    {
        $commande = (new Commande())->setModeLivraison(ModeLivraison::RETRAIT);

        $this->assertNull($commande->getAdresseLivraisonFigee());
        $this->assertSame('Retrait sur place (pas d\'adresse)', $commande->getAdresseLivraisonTexte());
    }

    public function testDetailDesFraisDeLivraison(): void
    {
        $commande = (new Commande())->setTotalTTC(12.38)->setFraisLivraison(4.5);

        $this->assertEqualsWithDelta(7.88, $commande->getSousTotalArticles(), 0.001);
        $this->assertEqualsWithDelta(4.5, $commande->getFraisLivraison(), 0.001);
    }

    /** @dataProvider telephones */
    public function testTelephoneFrancais(?string $valeur, bool $valide): void
    {
        $validateur = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $adresse = $this->adresse()->setTelephone($valeur);

        $erreurs = $validateur->validateProperty($adresse, 'telephone');

        $this->assertSame($valide, count($erreurs) === 0, (string) $valeur);
    }

    public static function telephones(): iterable
    {
        yield 'vide (facultatif)' => [null, true];
        yield 'blancs seuls' => ['   ', true];
        yield 'format courant' => ['06 12 34 56 78', true];
        yield 'collé' => ['0612345678', true];
        yield 'avec points' => ['06.12.34.56.78', true];
        yield 'international +33' => ['+33612345678', true];
        yield 'international avec espaces' => ['+33 6 12 34 56 78', true];
        yield 'international 0033' => ['0033612345678', true];
        yield 'lettres' => ['pas un numéro', false];
        yield 'trop court' => ['06 12 34', false];
        yield 'numéro étranger' => ['+44 7911 123456', false];
    }

    public function testSetTelephoneNormaliseLeVide(): void
    {
        $this->assertNull((new Adresse())->setTelephone('   ')->getTelephone());
        $this->assertSame('0612345678', (new Adresse())->setTelephone(' 0612345678 ')->getTelephone());
    }
}
