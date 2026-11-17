<?php

namespace App\Tests\Service;

use App\Service\IdentiteBoutique;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class IdentiteBoutiqueTest extends TestCase
{
    public function testExpediteurEtAdresse(): void
    {
        $b = new IdentiteBoutique(['email_expediteur' => 'a@b.test', 'nom_expediteur' => 'Ma Boutique', 'adresse_rue' => '1 rue X', 'adresse_code_postal' => '34000', 'adresse_ville' => 'Montpellier']);

        $this->assertSame('a@b.test', $b->expediteur()->getAddress());
        $this->assertSame('Ma Boutique', $b->expediteur()->getName());
        $this->assertSame('1 rue X, 34000 Montpellier', $b->adresseSiege());
        $this->assertSame('', $b->get('inconnu'));
    }

    public function testModeDemoParDefautParSecurite(): void
    {
        $this->assertTrue((new IdentiteBoutique([]))->modeDemo(), 'Sans réglage, on reste en démo');
        $this->assertFalse((new IdentiteBoutique(['mode_demo' => false]))->modeDemo());
    }

    public function testLeFichierDeConfigurationContientToutesLesInformationsLegalesEtResteFictif(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/boutique.yaml')['parameters']['boutique'];

        foreach (['mode_demo', 'nom_marque', 'raison_sociale', 'forme_juridique', 'capital', 'siret', 'rcs', 'tva_intracommunautaire',
            'adresse_rue', 'adresse_code_postal', 'adresse_ville', 'email_contact', 'email_expediteur', 'nom_expediteur', 'directeur_publication'] as $cle) {
            $this->assertArrayHasKey($cle, $config, "Clé « $cle » manquante dans config/boutique.yaml");
        }
        $this->assertStringContainsString('.invalid', $config['email_contact'], 'Valeurs fictives tant que la boutique n\'est pas configurée');
    }

    public function testAlertesParDefautVersLEmailDeContactEtSeuilGlobal(): void
    {
        $this->assertSame('contact@x.test', (new IdentiteBoutique(['email_contact' => 'contact@x.test', 'email_alertes' => '']))->emailAlertes());
        $this->assertSame('alertes@x.test', (new IdentiteBoutique(['email_contact' => 'contact@x.test', 'email_alertes' => 'alertes@x.test']))->emailAlertes());
        $this->assertSame(5, (new IdentiteBoutique([]))->seuilStockGlobal(), 'Seuil global de 5 par défaut');
        $this->assertSame(8, (new IdentiteBoutique(['stock_seuil_global' => 8]))->seuilStockGlobal());
    }

    public function testSeuilEffectifDUnProduit(): void
    {
        $produit = new \App\Entity\Produit();
        $this->assertSame(5, $produit->getSeuilEffectif(5));
        $this->assertSame(2, $produit->setSeuilAlerte(2)->getSeuilEffectif(5));
    }
}