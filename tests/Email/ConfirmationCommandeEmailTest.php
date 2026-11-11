<?php

namespace App\Tests\Email;

use App\Entity\Adresse;
use App\Entity\Box;
use App\Entity\Commande;
use App\Entity\CompositionBoxPersonnalisable;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Entity\User;
use App\Enum\ModeLivraison;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** E-mail de confirmation : articles, frais, destinataire, adresse, mode, numéro. */
class ConfirmationCommandeEmailTest extends KernelTestCase
{
    private function rendu(Commande $commande): string
    {
        self::bootKernel();

        return self::getContainer()->get('twig')->render('emails/confirmation_commande.html.twig', [
            'commande' => $commande,
            'retrait' => ['lieu' => 'Atelier Perline', 'adresse' => 'À définir, Montpellier', 'horaires' => 'Du mardi au samedi, 10h-18h', 'message' => 'Prête sous 24 h.'],
        ]);
    }

    private function commande(): Commande
    {
        $a = (new Produit())->setName('Cookie Pistache')->setPrix(3.0);
        $b = (new Produit())->setName('Cookie Noisette')->setPrix(3.0);
        $commande = (new Commande())->setNumeroCommande('PER-20261111-ABCDEF')->setTotalTTC(31.9)->setFraisLivraison(0);
        $commande->addLigneCommande((new LigneCommande())->setProduit($a)->setQuantite(2)->setPrixUnitaire(3.0));
        $ligneBox = (new LigneCommande())->setBox((new Box())->setNom('Box Gourmande')->setPrix(24.9))->setQuantite(1)->setPrixUnitaire(24.9);
        $ligneBox->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($a)->setQuantite(2));
        $ligneBox->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($b)->setQuantite(1));
        $commande->addLigneCommande($ligneBox);

        return $commande->setUser((new User())->setEmail('c@example.com')->setPrenom('Camille')->setNom('Martin'));
    }

    public function testLivraisonListeArticlesFraisDestinataireAdresseEtPays(): void
    {
        $adresse = (new Adresse())->setRue('12 rue des Tests')->setComplement('Bat. B')->setCodePostal('34000')->setVille('Montpellier')->setPays('France');
        $commande = $this->commande()->setTotalTTC(35.4)->setFraisLivraison(4.5)->figerAdresse($adresse, 'Camille Martin', '06 12 34 56 78');

        $html = $this->rendu($commande);

        $this->assertStringContainsString('PER-20261111-ABCDEF', $html);
        $this->assertStringContainsString('Cookie Pistache', $html);
        $this->assertStringContainsString('Box Gourmande', $html);
        $this->assertStringContainsString('2 × Cookie Pistache, 1 × Cookie Noisette', $html, 'Composition de la box');
        $this->assertStringContainsString('4,50 €', $html);
        $this->assertStringContainsString('35,40 €', $html);
        $this->assertStringContainsString('Camille Martin', $html);
        $this->assertStringContainsString('12 rue des Tests', $html);
        $this->assertStringContainsString('34000 Montpellier', $html);
        $this->assertStringContainsString('France', $html);
        $this->assertStringContainsString('06 12 34 56 78', $html);
    }

    public function testLivraisonOfferteEstAffichee(): void
    {
        $html = $this->rendu($this->commande()->setFraisLivraison(0));

        $this->assertStringContainsString('Offerte', $html);
    }

    public function testRetraitAfficheLeLieuEtPasDAdresse(): void
    {
        $commande = $this->commande()->setModeLivraison(ModeLivraison::RETRAIT)->setFraisLivraison(0);

        $html = $this->rendu($commande);

        $this->assertStringContainsString('Atelier Perline', $html);
        $this->assertStringContainsString('Du mardi au samedi, 10h-18h', $html);
        $this->assertStringNotContainsString('Livraison à :', $html);
    }
}
