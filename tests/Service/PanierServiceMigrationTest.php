<?php

namespace App\Tests\Service;

use App\Entity\Box;
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
 * Fusion du panier invité (session) dans le panier en base, à la connexion.
 * Base simulée : findByUser() ne « voit » un panier qu'après un flush, comme Doctrine
 * (pas d'enregistrement automatique avant une requête) — c'est ce qui causait l'erreur 1062.
 */
class PanierServiceMigrationTest extends TestCase
{
    private Session $session;
    /** @var list<object> */
    private array $persistes = [];
    private ?Panier $panierEnBase = null;   // ce que la base contient
    private array $enAttenteDeFlush = [];
    private User $user;
    private Produit $a;
    private Produit $b;
    private Box $box;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->a = (new Produit())->setName('A')->setPrix(3.0)->setStock(5);
        $this->b = (new Produit())->setName('B')->setPrix(4.0)->setStock(10);
        $this->box = (new Box())->setPrix6(12.99)->setPrix12(19.99)->setPrix24(29.99);
        (new \ReflectionProperty(Box::class, 'id'))->setValue($this->box, 7); // la migration relit la box par son id
        $this->persistes = [];
        $this->enAttenteDeFlush = [];
        $this->panierEnBase = null;
        $this->session = new Session(new MockArraySessionStorage());
    }

    private function service(): PanierService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistes[] = $o;
            $this->enAttenteDeFlush[] = $o;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            foreach ($this->enAttenteDeFlush as $o) {
                if ($o instanceof Panier) {
                    $this->panierEnBase = $o;
                }
            }
            $this->enAttenteDeFlush = [];
        });

        $catalogue = [Produit::class => [1 => $this->a, 2 => $this->b], Box::class => [7 => $this->box]];
        $em->method('getRepository')->willReturnCallback(function (string $classe) use ($catalogue) {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturnCallback(fn ($id) => $catalogue[$classe][$id] ?? null);

            return $repo;
        });

        $paniers = $this->createMock(PanierRepository::class);
        $paniers->method('findByUser')->willReturnCallback(fn () => $this->panierEnBase);

        $stack = $this->createMock(RequestStack::class);
        $stack->method('getSession')->willReturn($this->session);

        return new PanierService($em, $paniers, $stack, $this->createMock(Security::class));
    }

    private function nbPaniersCrees(): int
    {
        return count(array_filter($this->persistes, static fn ($o) => $o instanceof Panier));
    }

    /** @return list<LignePanier> */
    private function lignes(): array
    {
        return array_values(array_filter($this->persistes, static fn ($o) => $o instanceof LignePanier));
    }

    private function remplirSession(array $panier): void
    {
        $this->session->set('panier', $panier + ['date_expiration' => new \DateTime('+10 minutes')]);
    }

    public function testSansPanierEnBaseUnSeulPanierEstCree(): void
    {
        $this->remplirSession(['produits' => [1 => ['produit' => $this->a, 'quantite' => 2], 2 => ['produit' => $this->b, 'quantite' => 1]]]);

        $this->service()->migrerSessionVersBDD($this->user);

        $this->assertSame(1, $this->nbPaniersCrees(), 'Un seul panier par client (sinon erreur 1062)');
        $this->assertCount(2, $this->lignes());
        $this->assertNull($this->session->get('panier'));
    }

    public function testPanierExistantQuantitesAdditionneesEtNouvelArticleAjoute(): void
    {
        $existant = (new Panier())->setUser($this->user);
        $ligneA = (new LignePanier())->setProduit($this->a)->setQuantite(2);
        $existant->addLignePanier($ligneA);
        $this->panierEnBase = $existant;

        $this->remplirSession(['produits' => [1 => ['produit' => $this->a, 'quantite' => 2], 2 => ['produit' => $this->b, 'quantite' => 3]]]);

        $this->service()->migrerSessionVersBDD($this->user);

        $this->assertSame(0, $this->nbPaniersCrees(), 'Le panier existant est réutilisé');
        $this->assertSame(4, $ligneA->getQuantite(), '2 + 2 pour le même produit');
        $this->assertCount(1, $this->lignes(), 'Seul B est une nouvelle ligne');
        $this->assertNull($this->session->get('panier'));
    }

    public function testLeStockLimiteLaQuantiteFusionnee(): void
    {
        $existant = (new Panier())->setUser($this->user);
        $ligneA = (new LignePanier())->setProduit($this->a)->setQuantite(4); // stock de A : 5
        $existant->addLignePanier($ligneA);
        $this->panierEnBase = $existant;

        $this->remplirSession(['produits' => [1 => ['produit' => $this->a, 'quantite' => 3]]]);

        $this->service()->migrerSessionVersBDD($this->user);

        $this->assertSame(5, $ligneA->getQuantite(), 'Plafonné au stock (4 + 1)');
        $this->assertSame(['Stock limité : A ramené à 5.'], $this->session->getFlashBag()->get('warning'));
    }

    public function testBoxSimpleFusionneeEtBoxPersoAjouteeEnLigneSeparee(): void
    {
        $existant = (new Panier())->setUser($this->user);
        $ligneBox = (new LignePanier())->setBox($this->box)->setQuantite(1);
        $existant->addLignePanier($ligneBox);
        $this->panierEnBase = $existant;

        $this->remplirSession([
            'boxes' => [7 => ['box' => $this->box, 'quantite' => 2]],
            'boxes_perso' => [['box' => $this->box, 'cookies' => [1 => 2, 2 => 4], 'taille' => 6, 'prix' => 12.0]],
        ]);

        $this->service()->migrerSessionVersBDD($this->user);

        $this->assertSame(3, $ligneBox->getQuantite(), 'Box simple : 1 + 2');
        $lignes = $this->lignes();
        $this->assertCount(1, $lignes, 'La box perso est une nouvelle ligne');
        $this->assertSame(6, $lignes[0]->getTailleBox());
        $this->assertCount(2, $lignes[0]->getCompositionsPanier());
        $this->assertNull($this->session->get('panier'));
    }
}
