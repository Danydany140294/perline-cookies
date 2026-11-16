<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Les pages légales et le pied de page lisent l'identité dans config/boutique.yaml (rien en dur). */
class IdentiteDansLesPagesTest extends WebTestCase
{
    public function testMentionsLegalesCgvEtPolitiqueUtilisentLaConfiguration(): void
    {
        $client = static::createClient();
        $boutique = static::getContainer()->getParameter('boutique');

        foreach (['/mentions-legales', '/conditions-generales-de-vente', '/politique-de-confidentialite'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $html = $client->getResponse()->getContent();
            $this->assertStringContainsString(htmlspecialchars($boutique['raison_sociale'], ENT_QUOTES | ENT_HTML5), $html, $url);
            $this->assertStringNotContainsString('123 456 789', $html, $url);
            $this->assertStringNotContainsString('Sucre Holographique', $html, $url);
        }

        $client->request('GET', '/mentions-legales');
        $this->assertStringContainsString($boutique['siret'], $client->getResponse()->getContent());
        $this->assertStringContainsString($boutique['tva_intracommunautaire'], $client->getResponse()->getContent());
    }

    public function testBandeauDemoSeulementEnModeDemo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mentions-legales');
        $demo = static::getContainer()->getParameter('boutique')['mode_demo'];

        $this->assertSame($demo ? 1 : 0, $client->getCrawler()->filter('.bandeau-demo')->count());
    }
}
