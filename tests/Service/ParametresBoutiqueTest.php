<?php

namespace App\Tests\Service;

use App\Service\ParametresBoutique;
use App\Service\VueParametres;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ParametresBoutiqueTest extends TestCase
{
    private const BOUTIQUE = ['nom_marque' => 'Marque défaut', 'mode_demo' => true, 'stock_seuil_global' => 5, 'fonctions' => ['favoris' => false, 'avis' => false, 'points_fidelite' => false]];
    private const LIVRAISON = ['transporteur' => 'Colissimo', 'frais_forfait_centimes' => 450, 'modes' => ['livraison' => true, 'retrait' => true], 'delais' => ['jours_expedition' => ['1', '2', '3'], 'fermetures' => []]];
    private const RETRAIT = ['lieu' => 'Atelier'];

    /** @param list<array{cle: string, valeur: string}> $lignes */
    private function parametres(array $lignes, bool $tableAbsente = false): ParametresBoutique
    {
        $connexion = $this->createMock(Connection::class);
        if ($tableAbsente) {
            $connexion->method('fetchAllAssociative')->willThrowException(new \RuntimeException('Table inconnue'));
        } else {
            $connexion->method('fetchAllAssociative')->willReturn($lignes);
        }

        return new ParametresBoutique($connexion, self::BOUTIQUE, self::LIVRAISON, self::RETRAIT);
    }

    public function testSansSurchargeOnRetrouveLesValeursParDefaut(): void
    {
        $p = $this->parametres([]);

        $this->assertSame(self::BOUTIQUE, $p->boutique());
        $this->assertSame(self::LIVRAISON, $p->livraison());
        $this->assertSame(self::RETRAIT, $p->retrait());
    }

    public function testUneSurchargeRemplaceLaValeurParDefautSansToucherAuxAutres(): void
    {
        $p = $this->parametres([
            ['cle' => 'boutique.nom_marque', 'valeur' => json_encode('Nouvelle Marque')],
            ['cle' => 'boutique.fonctions.avis', 'valeur' => 'true'],
            ['cle' => 'livraison.frais_forfait_centimes', 'valeur' => '690'],
            ['cle' => 'livraison.delais.jours_expedition', 'valeur' => json_encode(['2', '4'])],
            ['cle' => 'retrait.lieu', 'valeur' => json_encode('Boutique centre-ville')],
        ]);

        $this->assertSame('Nouvelle Marque', $p->boutique()['nom_marque']);
        $this->assertTrue($p->boutique()['fonctions']['avis']);
        $this->assertFalse($p->boutique()['fonctions']['favoris']);
        $this->assertTrue($p->boutique()['mode_demo']);
        $this->assertSame(690, $p->livraison()['frais_forfait_centimes']);
        $this->assertSame(['2', '4'], $p->livraison()['delais']['jours_expedition']);
        $this->assertSame([], $p->livraison()['delais']['fermetures']);
        $this->assertSame('Colissimo', $p->livraison()['transporteur']);
        $this->assertSame('Boutique centre-ville', $p->retrait()['lieu']);
    }

    public function testUneCleInconnueEnBaseNEstJamaisAppliquee(): void
    {
        $p = $this->parametres([['cle' => 'boutique.stripe_secret', 'valeur' => json_encode('sk_test_x')], ['cle' => 'boutique.nouveau', 'valeur' => '1']]);

        $this->assertSame(self::BOUTIQUE, $p->boutique());
    }

    public function testTableAbsenteOuBaseInjoignableRetombeSurLesValeursParDefaut(): void
    {
        $p = $this->parametres([], true);

        $this->assertSame(self::BOUTIQUE, $p->boutique());
        $this->assertSame(450, $p->livraison()['frais_forfait_centimes']);
    }

    public function testSansConnexionOnResteAuxValeursParDefaut(): void
    {
        $p = new ParametresBoutique(null, self::BOUTIQUE, self::LIVRAISON, self::RETRAIT);

        $this->assertSame(self::BOUTIQUE, $p->boutique());
    }

    public function testValeursEffectivesEtParDefautSontNormalisees(): void
    {
        $p = $this->parametres([['cle' => 'livraison.delais.jours_expedition', 'valeur' => json_encode(['5'])]]);

        $this->assertSame(['1', '2', '3'], $p->valeursParDefaut()['livraison.delais.jours_expedition']);
        $this->assertSame(['5'], $p->valeursEffectives()['livraison.delais.jours_expedition']);
        $this->assertSame(450, $p->valeursEffectives()['livraison.frais_forfait_centimes']);
        $this->assertTrue($p->valeursEffectives()['boutique.mode_demo']);
        $this->assertSame(5, $p->valeursEffectives()['boutique.stock_seuil_global']);
    }

    public function testNormaliser(): void
    {
        $this->assertSame(435, ParametresBoutique::normaliser(ParametresBoutique::TYPE_EUROS, 434.99999999));
        $this->assertSame(['1', '2', '3'], ParametresBoutique::normaliser(ParametresBoutique::TYPE_JOURS, ' 1, 2 ,,3 '));
        $this->assertSame([], ParametresBoutique::normaliser(ParametresBoutique::TYPE_DATES, ''));
        $this->assertTrue(ParametresBoutique::normaliser(ParametresBoutique::TYPE_BOOLEEN, '1'));
        $this->assertSame('abc', ParametresBoutique::normaliser(ParametresBoutique::TYPE_TEXTE, '  abc '));
        $this->assertSame('', ParametresBoutique::normaliser(ParametresBoutique::TYPE_TEXTE, null));
    }

    public function testLaVueTwigLitLesValeursALaDemande(): void
    {
        $vue = new VueParametres($this->parametres([['cle' => 'boutique.nom_marque', 'valeur' => json_encode('Vue')]]), 'boutique');

        $this->assertTrue(isset($vue['nom_marque']));
        $this->assertSame('Vue', $vue['nom_marque']);
        $this->assertFalse($vue['fonctions']['favoris']);
        $this->assertNull($vue['inexistant']);
        $this->expectException(\LogicException::class);
        $vue['nom_marque'] = 'interdit';
    }

    public function testAucunSecretNEstModifiableEtToutChampExisteDansLesFichiersDeConfiguration(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/boutique.yaml')['parameters']['boutique'];
        $services = Yaml::parseFile(dirname(__DIR__, 2) . '/config/services.yaml')['parameters'];
        $p = new ParametresBoutique(null, $config, $services['livraison'], $services['retrait']);

        foreach (array_keys(ParametresBoutique::CHAMPS) as $cle) {
            $this->assertDoesNotMatchRegularExpression('/stripe|secret|dsn|password|mot_de_passe|token|cle_api/i', $cle, "Le champ « $cle » ressemble à un secret");
            $this->assertArrayHasKey($cle, $p->valeursParDefaut());
        }
        $this->assertCount(count(ParametresBoutique::CHAMPS), $p->valeursParDefaut());
    }
}
