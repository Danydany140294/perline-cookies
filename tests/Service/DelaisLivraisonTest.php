<?php

namespace App\Tests\Service;

use App\Service\DelaisLivraison;
use App\Service\FormatDateFr;
use PHPUnit\Framework\TestCase;

/**
 * Délais en jours ouvrés : préparation 48 h ouvrées, expédition du lundi au mercredi (produits frais),
 * puis 2 à 5 jours ouvrés Colissimo. Calendrier de novembre 2026 : lundi 9, mardi 10, mercredi 11, jeudi 12, vendredi 13...
 */
class DelaisLivraisonTest extends TestCase
{
    private function periode(DelaisLivraison $d, string $commande): array
    {
        $e = $d->estimer(new \DateTimeImmutable($commande, new \DateTimeZone('Europe/Paris')));

        return [$e['expedition']->format('D Y-m-d'), $e['debut']->format('D Y-m-d'), $e['fin']->format('D Y-m-d')];
    }

    /** @dataProvider commandes */
    public function testCalendrierParJourDeCommande(string $commande, string $expedition, string $debut, string $fin): void
    {
        $this->assertSame([$expedition, $debut, $fin], $this->periode(new DelaisLivraison(), $commande), $commande);
    }

    public static function commandes(): iterable
    {
        yield 'lundi : prêt mercredi, expédié mercredi' => ['2026-11-09 10:00', 'Wed 2026-11-11', 'Fri 2026-11-13', 'Wed 2026-11-18'];
        yield 'mardi : prêt jeudi, expédié lundi suivant' => ['2026-11-10 10:00', 'Mon 2026-11-16', 'Wed 2026-11-18', 'Mon 2026-11-23'];
        yield 'mercredi : prêt vendredi, expédié lundi' => ['2026-11-11 23:30', 'Mon 2026-11-16', 'Wed 2026-11-18', 'Mon 2026-11-23'];
        yield 'jeudi : prêt lundi, expédié lundi' => ['2026-11-12 08:00', 'Mon 2026-11-16', 'Wed 2026-11-18', 'Mon 2026-11-23'];
        yield 'vendredi : prêt mardi, expédié mardi' => ['2026-11-13 15:00', 'Tue 2026-11-17', 'Thu 2026-11-19', 'Tue 2026-11-24'];
        yield 'samedi compte comme le lundi suivant' => ['2026-11-14 12:00', 'Wed 2026-11-18', 'Fri 2026-11-20', 'Wed 2026-11-25'];
        yield 'dimanche compte comme le lundi suivant' => ['2026-11-15 12:00', 'Wed 2026-11-18', 'Fri 2026-11-20', 'Wed 2026-11-25'];
    }

    public function testLesJoursDeFermetureNeComptentPas(): void
    {
        // Jeudi 12 : sans fermeture, prêt lundi 16 ; avec le lundi 16 fermé, prêt mardi 17
        $ferme = new DelaisLivraison(['delais' => ['fermetures' => ['2026-11-16']] + DelaisLivraison::CONFIG_PAR_DEFAUT]);

        $this->assertSame(['Tue 2026-11-17', 'Thu 2026-11-19', 'Tue 2026-11-24'], $this->periode($ferme, '2026-11-12 08:00'));
    }

    public function testUnePlageDeFermetureEstSautee(): void
    {
        // Fermeture du 16 au 20 novembre : une commande du lundi 9 est prête mercredi 11, expédiée mercredi 11 (avant la fermeture)
        $conges = new DelaisLivraison(['delais' => ['fermetures' => ['2026-11-16/2026-11-20']] + DelaisLivraison::CONFIG_PAR_DEFAUT]);
        $this->assertSame('Wed 2026-11-11', $this->periode($conges, '2026-11-09 10:00')[0]);

        // Commande du jeudi 12 : prête... le 23 (lundi) car la semaine du 16 est fermée ; expédiée le 23
        $this->assertSame('Mon 2026-11-23', $this->periode($conges, '2026-11-12 10:00')[0]);
    }

    public function testJoursDExpeditionReglables(): void
    {
        $toutLaSemaine = new DelaisLivraison(['delais' => ['jours_expedition' => [1, 2, 3, 4, 5]] + DelaisLivraison::CONFIG_PAR_DEFAUT]);

        // Mercredi 11 : prêt vendredi 13, expédié vendredi 13 (autorisé)
        $this->assertSame('Fri 2026-11-13', $this->periode($toutLaSemaine, '2026-11-11 10:00')[0]);
    }

    public function testValeursEnvironnementSousFormeDeTexteEtVides(): void
    {
        // Les variables d'environnement csv donnent des chaînes (et [null] quand elles sont vides)
        $d = new DelaisLivraison(['delais' => ['jours_expedition' => ['1', '2', '3'], 'fermetures' => [null]] + DelaisLivraison::CONFIG_PAR_DEFAUT]);

        $this->assertSame('Wed 2026-11-11', $this->periode($d, '2026-11-09 10:00')[0]);
        $this->assertSame('lundi, mardi et mercredi', $d->joursExpeditionTexte());
    }

    public function testJamaisDExpeditionUnJourFermeOuNonAutorise(): void
    {
        $d = new DelaisLivraison(['delais' => ['fermetures' => ['2026-12-24/2027-01-03']] + DelaisLivraison::CONFIG_PAR_DEFAUT]);
        $debut = new \DateTimeImmutable('2026-10-01', new \DateTimeZone('Europe/Paris'));

        for ($i = 0; $i < 220; $i++) {
            $e = $d->estimer($debut->modify("+$i days"));

            $this->assertContains((int) $e['expedition']->format('N'), [1, 2, 3], 'Expédition lundi à mercredi');
            $this->assertTrue($d->estOuvre($e['expedition']), 'Jamais un jour fermé');
            $this->assertTrue($d->estOuvre($e['debut']) && $d->estOuvre($e['fin']), 'Livraison un jour ouvré');
            $this->assertGreaterThan($e['expedition'], $e['debut']);
            $this->assertGreaterThanOrEqual($e['debut'], $e['fin']);
            $this->assertGreaterThanOrEqual($e['commande'], $e['pret_le']);
        }
    }

    public function testFormatEnFrancais(): void
    {
        $d = new DelaisLivraison();
        $commande = new \DateTimeImmutable('2026-11-09 10:00', new \DateTimeZone('Europe/Paris'));

        $this->assertSame('entre le vendredi 13 novembre et le mercredi 18 novembre', $d->periode($commande));
        $this->assertSame('Livraison estimée entre le vendredi 13 novembre et le mercredi 18 novembre', $d->phrase($commande));
        $this->assertSame('48', (string) $d->preparationHeures());
    }

    public function testChangementDAnneeAjouteLAnnee(): void
    {
        $periode = FormatDateFr::periode(new \DateTimeImmutable('2026-12-30'), new \DateTimeImmutable('2027-01-06'));

        $this->assertSame('entre le mercredi 30 décembre 2026 et le mercredi 6 janvier 2027', $periode);
    }

    public function testLaConfigurationDuProjetEstCelleDecidee(): void
    {
        $conf = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/config/services.yaml')['parameters']['livraison']['delais'];

        $this->assertSame(2, $conf['preparation_jours_ouvres'], 'Préparation 48 h ouvrées');
        $this->assertSame(2, $conf['transit_min_jours_ouvres']);
        $this->assertSame(5, $conf['transit_max_jours_ouvres'], 'Colissimo 2 à 5 jours ouvrés');
    }
}
