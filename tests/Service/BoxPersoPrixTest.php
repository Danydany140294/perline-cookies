<?php

namespace App\Tests\Service;

use App\Entity\Box;
use App\Entity\LignePanier;
use App\Entity\Produit;
use App\Entity\User;
use App\Repository\PanierRepository;
use App\Service\PanierService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Validation;

/**
 * Prix de la box personnalisable : UNE seule source, les prix de l'admin (prix6 / prix12 / prix24).
 * Plus de constantes dupliquées, plus de prix de repli (le champ « prix » de la box, 19,90 €).
 */
class BoxPersoPrixTest extends TestCase
{
    private function boxPerso(?float $p6 = 12.99, ?float $p12 = 19.99, ?float $p24 = 29.99): Box
    {
        $box = (new Box())->setType('personnalisable')->setNom('Box Personnalisable')->setPrix(19.90)->setPrix6($p6)->setPrix12($p12)->setPrix24($p24);
        (new \ReflectionProperty(Box::class, 'id'))->setValue($box, 4);

        return $box;
    }

    // ───────────── Box::getPrixPourTaille ─────────────

    public function testLePrixDUneTailleVientDeLAdmin(): void
    {
        $box = $this->boxPerso();

        $this->assertSame(12.99, $box->getPrixPourTaille(6));
        $this->assertSame(19.99, $box->getPrixPourTaille(12));
        $this->assertSame(29.99, $box->getPrixPourTaille(24));
    }

    public function testPrixAbsentNulOuNegatifEtTailleInconnueDonnentNullSansPrixDeRepli(): void
    {
        $box = $this->boxPerso(null, 0.0, -5.0);

        $this->assertNull($box->getPrixPourTaille(6));
        $this->assertNull($box->getPrixPourTaille(12));
        $this->assertNull($box->getPrixPourTaille(24));
        $this->assertNull($this->boxPerso()->getPrixPourTaille(7), 'Taille non prévue');
        $this->assertSame(19.90, $box->getPrix(), 'Le champ « prix » existe encore mais n\'est jamais utilisé pour une taille');
    }

    // ───────────── Validation dans l'admin ─────────────

    public function testUneBoxPersoSansPrixPourUneTailleEstRefuseeParLeValidateur(): void
    {
        $validateur = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $violations = $validateur->validate($this->boxPerso(12.99, null, 29.99));

        $this->assertCount(1, $violations);
        $this->assertSame('prix12', $violations[0]->getPropertyPath());
        $this->assertStringContainsString('12 cookies', (string) $violations[0]->getMessage());
        $this->assertCount(0, $validateur->validate($this->boxPerso()), 'Prix complets : valide');
    }

    public function testUneBoxClassiqueNaPasBesoinDePrixParTaille(): void
    {
        $validateur = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validateur->validate((new Box())->setType('fixe')->setPrix(24.9)));
    }

    // ───────────── Prix d'une ligne de panier ─────────────

    public function testLaLigneDePanierEstFactureeAuPrixAdminDeSaTaille(): void
    {
        $box = $this->boxPerso();

        foreach ([6 => 12.99, 12 => 19.99, 24 => 29.99] as $taille => $attendu) {
            $ligne = (new LignePanier())->setBox($box)->setQuantite(1)->setTailleBox($taille);
            $this->assertSame($attendu, $ligne->getPrixUnitaire(), "Taille $taille");
        }
    }

    public function testSansPrixDefiniLaLigneNEstJamaisFactureeAuPrixDeRepli(): void
    {
        $ligne = (new LignePanier())->setBox($this->boxPerso(12.99, null, 29.99))->setQuantite(1)->setTailleBox(12);
        $this->assertSame(0.0, $ligne->getPrixUnitaire(), 'Ni 19,90 € ni 24 € : 0, et le paiement est bloqué');

        $sansTaille = (new LignePanier())->setBox($this->boxPerso())->setQuantite(1);
        $this->assertSame(0.0, $sansTaille->getPrixUnitaire());
    }

    public function testLesPrixDesCookiesEtDesBoxesClassiquesNeChangentPas(): void
    {
        $this->assertSame(3.5, (new LignePanier())->setProduit((new Produit())->setPrix(3.5))->getPrixUnitaire());
        $this->assertSame(24.9, (new LignePanier())->setBox((new Box())->setType('fixe')->setPrix(24.9))->getPrixUnitaire());
    }

    // ───────────── Plus de source dupliquée ─────────────

    public function testPlusAucuneConstanteDePrixDeBoxPersoDansLeCode(): void
    {
        foreach ([LignePanier::class, PanierService::class] as $classe) {
            $this->assertArrayNotHasKey('PRIX_BOX_PERSO', (new \ReflectionClass($classe))->getConstants(), $classe);
        }

        $racine = dirname(__DIR__, 2) . '/src';
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            if ($fichier->getExtension() === 'php') {
                $this->assertStringNotContainsString('PRIX_BOX_PERSO', file_get_contents($fichier->getPathname()), $fichier->getPathname());
            }
        }
    }

    // ───────────── Panier de session (invité) ─────────────

    private function service(Box $boxEnBase, Session $session): PanierService
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturnCallback(fn ($id) => $id === 4 ? $boxEnBase : (new Produit())->setName('C')->setPrix(3.0)->setStock(50));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $stack = $this->createMock(RequestStack::class);
        $stack->method('getSession')->willReturn($session);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        return new PanierService($em, $this->createMock(PanierRepository::class), $stack, $security);
    }

    private function sessionAvecBoxPerso(Box $box, float $prixStocke): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('panier', [
            'produits' => [], 'boxes' => [],
            'boxes_perso' => [3 => ['box' => $box, 'cookies' => [1 => 12], 'taille' => 12, 'prix' => $prixStocke]],
            'date_expiration' => new \DateTime('+10 minutes'),
        ]);

        return $session;
    }

    public function testLePanierDeSessionRelitLePrixAdminEtIgnoreUnPrixPerime(): void
    {
        $box = $this->boxPerso();
        // Prix stocké à l'époque : 24 € (ancienne constante). L'admin dit 19,99 €.
        $panier = $this->service($box, $this->sessionAvecBoxPerso($box, 24.00))->getPanier();

        $this->assertSame(19.99, $panier['boxes_perso'][3]['prix']);
        $this->assertArrayHasKey(3, $panier['boxes_perso'], 'La clé (identifiant de la ligne) est conservée');
    }

    public function testLePrixSuitLaModificationFaiteDansLAdmin(): void
    {
        $box = $this->boxPerso();
        $service = $this->service($box, $this->sessionAvecBoxPerso($box, 19.99));

        $box->setPrix12(21.49); // l'admin change le prix

        $this->assertSame(21.49, $service->getPanier()['boxes_perso'][3]['prix']);
    }

    public function testUneBoxPersoDontLePrixAEteSupprimeEstRetireDuPanierInvite(): void
    {
        $box = $this->boxPerso();
        $service = $this->service($box, $this->sessionAvecBoxPerso($box, 19.99));

        $box->setPrix12(null);

        $this->assertSame([], $service->getPanier()['boxes_perso']);
    }

    public function testAjouterUneBoxPersoSansPrixPourLaTailleEstRefuse(): void
    {
        $box = $this->boxPerso(12.99, null, 29.99);
        $session = new Session(new MockArraySessionStorage());

        try {
            $this->service($box, $session)->ajouterBoxPersonnalisable($box, [1 => 12], 12);
            $this->fail('Une box sans prix a été ajoutée');
        } catch (\Exception $e) {
            $this->assertStringContainsString('n\'est pas disponible pour le moment', $e->getMessage());
        }
        $this->assertNull($session->get('panier'), 'Rien n\'est ajouté');
    }

    public function testAjouterUneBoxPersoStockeLePrixAdminEtPasUneConstante(): void
    {
        $box = $this->boxPerso();
        $session = new Session(new MockArraySessionStorage());

        $this->service($box, $session)->ajouterBoxPersonnalisable($box, [1 => 12], 12);

        $this->assertSame(19.99, array_values($session->get('panier')['boxes_perso'])[0]['prix']);
    }
}
