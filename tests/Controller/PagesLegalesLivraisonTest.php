<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Les CGV reprennent la configuration de livraison (frais, seuil, zone, délais) : jamais un texte qui la contredit. */
class PagesLegalesLivraisonTest extends WebTestCase
{
    public function testLesCgvReprennentLaConfigurationDeLivraison(): void
    {
        $client = static::createClient();
        $client->request('GET', '/conditions-generales-de-vente');
        $this->assertResponseIsSuccessful();
        $texte = preg_replace('/\s+/', ' ', $client->getCrawler()->filter('body')->text());

        $this->assertStringContainsString('forfait de 4,50 €', $texte);
        $this->assertStringContainsString('offerte à partir de 50 € d\'achat', $texte);
        $this->assertStringContainsString('hors Corse', $texte);
        $this->assertStringContainsString('48 heures ouvrées', $texte);
        $this->assertStringContainsString('lundi, mardi et mercredi', $texte);
        $this->assertStringContainsString('2 à 5 jours ouvrés', $texte);
        $this->assertStringContainsString('Colissimo', $texte);
        $this->assertStringNotContainsString('Chronopost', $texte);
        $this->assertStringNotContainsString('en fonction du poids', $texte);
        $this->assertStringNotContainsString('40 €', $texte);
    }
}
