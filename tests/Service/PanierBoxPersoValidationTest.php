<?php

namespace App\Tests\Service;

use App\Entity\Box;
use App\Entity\CompositionPanierPersonnalisable;
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
use Symfony\Component\Security\Core\Security;

/**
 * Box personnalisable : le serveur ne doit JAMAIS faire confiance à la composition envoyée par le navigateur.
 * (Faille reproduite : {"1": 10, "2": -4} pour une box de 6 a une somme de 6 et passait.)
 */
class PanierBoxPersoValidationTest extends TestCase
{
    private Produit $a;
    private Produit $b;
    private Produit $epuise;
    private Produit $indisponible;
    private Box $box;
    /** @var list<object> */
    private array $persistes = [];

    protected function setUp(): void
    {
        $this->a = $this->cookie(1, 'Cookie A', 20);
        $this->b = $this->cookie(2, 'Cookie B', 20);
        $this->epuise = $this->cookie(3, 'Cookie Epuisé', 2);
        $this->indisponible = $this->cookie(4, 'Cookie Retiré', 50)->setDisponible(false);
        $this->box = (new Box())->setType('personnalisable');
        $this->persistes = [];
    }

    private function cookie(int $id, string $nom, int $stock): Produit
    {
        $p = (new Produit())->setName($nom)->setPrix(3.0)->setStock($stock)->setDisponible(true);
        (new \ReflectionProperty(Produit::class, 'id'))->setValue($p, $id);

        return $p;
    }

    private function service(): PanierService
    {
        $catalogue = [1 => $this->a, 2 => $this->b, 3 => $this->epuise, 4 => $this->indisponible];

        $repoProduits = $this->createMock(EntityRepository::class);
        $repoProduits->method('find')->willReturnCallback(fn ($id) => $catalogue[$id] ?? null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repoProduits);
        $em->method('persist')->willReturnCallback(function (object $o): void { $this->persistes[] = $o; });

        $paniers = $this->createMock(PanierRepository::class);
        $paniers->method('findByUser')->willReturn((new Panier())->setUser(new User()));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new User());

        return new PanierService($em, $paniers, $this->createMock(RequestStack::class), $security);
    }

    private function refuse(array $cookies, int $taille, string $messageContient): void
    {
        try {
            $this->service()->ajouterBoxPersonnalisable($this->box, $cookies, $taille);
            $this->fail('La composition aurait dû être refusée : ' . json_encode($cookies));
        } catch (\Exception $e) {
            $this->assertStringContainsString($messageContient, $e->getMessage());
        }

        $this->assertSame([], $this->persistes, 'Rien ne doit être enregistré dans le panier');
    }

    public function testQuantiteNegativeEstRefuseeMemeSiLaSommeEstBonne(): void
    {
        // 10 + (-4) = 6 : la somme passait, le client obtenait 10 cookies pour le prix de 6
        $this->refuse([1 => 10, 2 => -4], 6, 'quantité');
    }

    public function testCookieInexistantEstRefuse(): void
    {
        $this->refuse([1 => 1, 99999 => 5], 6, 'introuvable');
    }

    public function testBoxVideAvecUnSeulIdentifiantFauxEstRefusee(): void
    {
        $this->refuse([99999 => 6], 6, 'introuvable');
    }

    public function testCookieIndisponibleEstRefuse(): void
    {
        $this->refuse([1 => 3, 4 => 3], 6, 'Cookie Retiré');
    }

    public function testQuantiteSuperieureAuStockEstRefuseeAvecLeNomDuCookie(): void
    {
        $this->refuse([1 => 3, 3 => 3], 6, 'Stock insuffisant pour Cookie Epuisé');
    }

    public function testQuantiteNonEntiereEstRefusee(): void
    {
        $this->refuse([1 => '3', 2 => 3.5], 6, 'quantité');
    }

    public function testSommeDifferenteDeLaTailleEstRefusee(): void
    {
        $this->refuse([1 => 2, 2 => 2], 6, 'doit contenir 6');
    }

    public function testCompositionValideEstAccepteeEtLesZerosSontIgnores(): void
    {
        $this->service()->ajouterBoxPersonnalisable($this->box, [1 => 4, 2 => 2, 3 => 0], 6);

        $lignes = array_values(array_filter($this->persistes, static fn ($o) => $o instanceof LignePanier));
        $compos = array_values(array_filter($this->persistes, static fn ($o) => $o instanceof CompositionPanierPersonnalisable));

        $this->assertCount(1, $lignes);
        $this->assertCount(2, $compos, 'Le cookie à 0 n\'est pas enregistré');
        $this->assertSame([4, 2], array_map(static fn ($c) => $c->getQuantite(), $compos));
    }
}
