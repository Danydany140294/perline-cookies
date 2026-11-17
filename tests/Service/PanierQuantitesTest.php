<?php

namespace App\Tests\Service;

use App\Entity\Box;
use App\Entity\BoxProduit;
use App\Entity\LignePanier;
use App\Entity\Panier;
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

/**
 * Quantités du panier : jamais négatives, nulles à l'ajout, ni au-delà du stock des cookies
 * (cookies seuls, boxes classiques et box perso CUMULÉS). Aucune base de données.
 */
class PanierQuantitesTest extends TestCase
{
    private Produit $a;
    private Produit $b;
    private Box $boxClassique;
    private Panier $panier;
    private Session $session;
    private ?User $user = null;
    private int $flushs = 0;
    /** @var list<object> */
    private array $supprimes = [];
    private int $prochainId = 100;

    protected function setUp(): void
    {
        $this->a = $this->cookie(1, 'Cookie A', 5);
        $this->b = $this->cookie(2, 'Cookie B', 10);
        // Box classique : 2 × A + 1 × B
        $this->boxClassique = new Box();
        $this->idDe($this->boxClassique, 7);
        $this->boxClassique->addComposition((new BoxProduit())->setProduit($this->a)->setQuantite(2));
        $this->boxClassique->addComposition((new BoxProduit())->setProduit($this->b)->setQuantite(1));

        $this->user = new User();
        $this->panier = (new Panier())->setUser($this->user);
        $this->session = new Session(new MockArraySessionStorage());
        $this->flushs = 0;
        $this->supprimes = [];
    }

    private function idDe(object $o, int $id): void
    {
        (new \ReflectionProperty($o::class, 'id'))->setValue($o, $id);
    }

    private function cookie(int $id, string $nom, int $stock): Produit
    {
        $p = (new Produit())->setName($nom)->setPrix(3.0)->setStock($stock);
        $this->idDe($p, $id);

        return $p;
    }

    private function service(bool $connecte = true): PanierService
    {
        $catalogue = [Produit::class => [1 => $this->a, 2 => $this->b], Box::class => [7 => $this->boxClassique]];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function (string $classe) use ($catalogue) {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturnCallback(fn ($id) => $catalogue[$classe][$id] ?? null);

            return $repo;
        });
        $em->method('flush')->willReturnCallback(function (): void { $this->flushs++; });
        $em->method('remove')->willReturnCallback(function (object $o): void { $this->supprimes[] = $o; });

        $paniers = $this->createMock(PanierRepository::class);
        $paniers->method('findByUser')->willReturn($this->panier);

        $stack = $this->createMock(RequestStack::class);
        $stack->method('getSession')->willReturn($this->session);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($connecte ? $this->user : null);

        return new PanierService($em, $paniers, $stack, $security);
    }

    private function ligneProduit(Produit $p, int $quantite): LignePanier
    {
        $ligne = (new LignePanier())->setProduit($p)->setQuantite($quantite);
        $this->idDe($ligne, $this->prochainId++);
        $this->panier->addLignePanier($ligne);

        return $ligne;
    }

    private function ligneBox(Box $b, int $quantite): LignePanier
    {
        $ligne = (new LignePanier())->setBox($b)->setQuantite($quantite);
        $this->idDe($ligne, $this->prochainId++);
        $this->panier->addLignePanier($ligne);

        return $ligne;
    }

    // ───────────── ajout ─────────────

    public function testAjoutDeCookieCumuleAvecLePanierEtRefuseAuDelaDuStock(): void
    {
        $this->ligneProduit($this->a, 4); // stock de A : 5

        $this->expectExceptionMessage('Stock insuffisant pour Cookie A : 6 demandé(s), 5 disponible(s).');
        $this->service()->ajouterProduit($this->a, 2);
    }

    public function testAjoutDeCookieJusquAuStockExactEstAccepte(): void
    {
        $this->ligneProduit($this->a, 4);

        $this->service()->ajouterProduit($this->a, 1);

        $this->assertSame(5, $this->panier->getLignesPanier()->first()->getQuantite());
    }

    public function testLesCookiesDesBoxesDuPanierCompteContreLeStockDuCookieSeul(): void
    {
        $this->ligneBox($this->boxClassique, 2); // 2 boxes = 4 × A déjà dans le panier

        $this->expectExceptionMessage('Stock insuffisant pour Cookie A : 6 demandé(s), 5 disponible(s).');
        $this->service()->ajouterProduit($this->a, 2);
    }

    public function testQuantiteNulleOuNegativeEstRefuseeALAjout(): void
    {
        foreach ([0, -3] as $quantite) {
            try {
                $this->service()->ajouterProduit($this->a, $quantite);
                $this->fail('Quantité ' . $quantite . ' acceptée');
            } catch (\Exception $e) {
                $this->assertSame('Quantité invalide', $e->getMessage());
            }
            try {
                $this->service()->ajouterBox($this->boxClassique, $quantite);
                $this->fail('Quantité de box ' . $quantite . ' acceptée');
            } catch (\Exception $e) {
                $this->assertSame('Quantité invalide', $e->getMessage());
            }
        }
    }

    public function testAjoutDeBoxVerifieLeStockDeSesCookies(): void
    {
        // 3 boxes = 6 × A (stock 5)
        $this->expectExceptionMessage('Stock insuffisant pour Cookie A : 6 demandé(s), 5 disponible(s).');
        $this->service()->ajouterBox($this->boxClassique, 3);
    }

    public function testBoxInactiveOuSansRecetteNEstPasVendable(): void
    {
        $inactive = $this->boxClassique;
        $inactive->setIsActive(false);
        try {
            $this->service()->ajouterBox($inactive, 1);
            $this->fail('Box inactive acceptée');
        } catch (\Exception $e) {
            $this->assertStringContainsString('n\'est plus disponible', $e->getMessage());
        }

        $sansRecette = $this->boxClassique = new Box();
        $this->idDe($sansRecette, 8);
        $this->expectExceptionMessage('pas disponible');
        $this->service()->ajouterBox($sansRecette, 1);
    }

    // ───────────── boutons « + » / « − » (client connecté) ─────────────

    public function testPlusDansLeStockAugmenteLaQuantite(): void
    {
        $ligne = $this->ligneProduit($this->a, 3);

        $this->assertSame(4, $this->service()->modifierQuantiteLigne($this->user, $ligne->getId(), 1));
        $this->assertSame(4, $ligne->getQuantite());
    }

    public function testPlusAuDelaDuStockEstRefuseEtLaQuantiteResteInchangee(): void
    {
        $ligne = $this->ligneProduit($this->a, 5);

        try {
            $this->service()->modifierQuantiteLigne($this->user, $ligne->getId(), 1);
            $this->fail('Dépassement du stock accepté');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Stock insuffisant pour Cookie A : 6 demandé(s), 5 disponible(s)', $e->getMessage());
        }

        $this->assertSame(5, $ligne->getQuantite(), 'La quantité n\'est pas modifiée');
        $this->assertSame(0, $this->flushs);
    }

    public function testPlusSurUneBoxCompteLesCookiesDeLaRecetteEtDuResteDuPanier(): void
    {
        $ligneBox = $this->ligneBox($this->boxClassique, 1);   // 2 × A
        $this->ligneProduit($this->a, 1);                      // + 1 × A  = 3 × A

        $this->assertSame(2, $this->service()->modifierQuantiteLigne($this->user, $ligneBox->getId(), 1)); // 5 × A : ok

        $this->expectExceptionMessage('Stock insuffisant pour Cookie A : 7 demandé(s), 5 disponible(s).');
        $this->service()->modifierQuantiteLigne($this->user, $ligneBox->getId(), 1); // 3 boxes = 6 + 1 = 7
    }

    public function testMoinsJusquAZeroSupprimeLaLigne(): void
    {
        $ligne = $this->ligneProduit($this->a, 1);

        $this->assertSame(0, $this->service()->modifierQuantiteLigne($this->user, $ligne->getId(), -1));
        $this->assertContains($ligne, $this->supprimes);
    }

    public function testVariationAbsurdeOuNulleEstRefusee(): void
    {
        $ligne = $this->ligneProduit($this->a, 1);

        foreach ([0, 1000, -1000] as $variation) {
            try {
                $this->service()->modifierQuantiteLigne($this->user, $ligne->getId(), $variation);
                $this->fail('Variation ' . $variation . ' acceptée');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, $ligne->getQuantite());
    }

    public function testPlusSurUneBoxDevenueInactiveEstRefuseeMaisMoinsResteAutorise(): void
    {
        $ligne = $this->ligneBox($this->boxClassique, 2);
        $this->boxClassique->setIsActive(false);

        $this->assertSame(1, $this->service()->modifierQuantiteLigne($this->user, $ligne->getId(), -1), 'On peut toujours réduire');

        $this->expectExceptionMessage('n\'est plus disponible');
        $this->service()->modifierQuantiteLigne($this->user, $ligne->getId(), 1);
    }

    public function testLigneInconnueEstIntrouvable(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service()->modifierQuantiteLigne($this->user, 99999, 1);
    }

    // ───────────── invité (panier de session) ─────────────

    public function testInvitePlusAuDelaDuStockEstRefuse(): void
    {
        $this->session->set('panier', ['produits' => [1 => ['produit' => $this->a, 'quantite' => 5]], 'boxes' => [], 'boxes_perso' => [], 'date_expiration' => new \DateTime('+10 minutes')]);

        $this->expectExceptionMessage('Stock insuffisant pour Cookie A : 6 demandé(s), 5 disponible(s).');
        $this->service(false)->modifierQuantiteSession(1, 'Cookie', 1);
    }

    public function testInvitePlusDansLeStockEtMoinsJusquAZero(): void
    {
        $this->session->set('panier', ['produits' => [1 => ['produit' => $this->a, 'quantite' => 2]], 'boxes' => [], 'boxes_perso' => [], 'date_expiration' => new \DateTime('+10 minutes')]);
        $service = $this->service(false);

        $this->assertSame(3, $service->modifierQuantiteSession(1, 'Cookie', 1));
        $this->assertSame(0, $service->modifierQuantiteSession(1, 'Cookie', -3));
        $this->assertArrayNotHasKey(1, $this->session->get('panier')['produits']);
    }
}
