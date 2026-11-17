<?php

namespace App\Tests\Service;

use App\Entity\Box;
use App\Entity\Commande;
use App\Entity\CommandeHistorique;
use App\Entity\CompositionBoxPersonnalisable;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Enum\CommandeStatut;
use App\Service\RestitutionStockService;
use App\Service\StockCookiesService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Remise en stock : décision de l'administrateur, une seule fois, jamais automatique. */
class RestitutionStockServiceTest extends TestCase
{
    private Produit $a;
    private Produit $b;
    private Commande $commande;
    /** @var list<object> */
    private array $persistes = [];

    protected function setUp(): void
    {
        $this->a = (new Produit())->setName('Cookie A')->setPrix(3.0)->setStock(10);
        $this->b = (new Produit())->setName('Cookie B')->setPrix(3.0)->setStock(10);

        // 1 × A seul + 2 boxes de composition figée (2 A + 1 B) : A = 1 + 4 = 5, B = 2
        $this->commande = (new Commande())->setNumeroCommande('PER-T')->setTotalTTC(10.0)->setStatut(CommandeStatut::REMBOURSEE)->setStockRetire(true);
        $this->commande->addLigneCommande((new LigneCommande())->setProduit($this->a)->setQuantite(1)->setPrixUnitaire(3.0));
        $ligneBox = (new LigneCommande())->setBox(new Box())->setQuantite(2)->setPrixUnitaire(10.0);
        $ligneBox->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($this->a)->setQuantite(2));
        $ligneBox->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($this->b)->setQuantite(1));
        $this->commande->addLigneCommande($ligneBox);
        $this->persistes = [];
    }

    private function service(): RestitutionStockService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void { $this->persistes[] = $o; });

        return new RestitutionStockService(new StockCookiesService(), $em);
    }

    public function testRemetEnStockLaCompositionFigeeUneSeuleFois(): void
    {
        $this->service()->restituer($this->commande, 'admin@creaself.com');

        $this->assertSame(15, $this->a->getStock());
        $this->assertSame(12, $this->b->getStock());
        $this->assertTrue($this->commande->isStockRestitue());
        $notes = array_filter($this->persistes, static fn ($o) => $o instanceof CommandeHistorique);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('5 × Cookie A', reset($notes)->getNote());
        $this->assertSame('admin@creaself.com', reset($notes)->getAuteur());
    }

    public function testUneSecondeRemiseEnStockEstRefusee(): void
    {
        $service = $this->service();
        $service->restituer($this->commande);

        $this->expectExceptionMessage('déjà été remis en vente');
        try {
            $service->restituer($this->commande);
        } finally {
            $this->assertSame(15, $this->a->getStock(), 'Pas de double remise en stock');
        }
    }

    public function testPossiblePourUneCommandeAnnulee(): void
    {
        $this->commande->setStatut(CommandeStatut::ANNULEE);

        $this->service()->restituer($this->commande);

        $this->assertSame(15, $this->a->getStock());
    }

    public function testRefuseePourUneCommandeEnCoursOuLivree(): void
    {
        foreach ([CommandeStatut::PAYEE, CommandeStatut::EN_PREPARATION, CommandeStatut::LIVREE, CommandeStatut::A_VERIFIER] as $statut) {
            $this->commande->setStatut($statut);
            try {
                $this->service()->restituer($this->commande);
                $this->fail('Remise en stock acceptée pour ' . $statut->value);
            } catch (\DomainException $e) {
                $this->assertStringContainsString('annulée ou remboursée', $e->getMessage());
            }
        }
        $this->assertSame(10, $this->a->getStock());
        $this->assertFalse($this->commande->isStockRestitue());
    }

    public function testRefuseeSiLeStockNAJamaisEteRetire(): void
    {
        $this->commande->setStockRetire(false); // ancienne commande, ou jamais payée

        $this->expectExceptionMessage('jamais été retiré');
        $this->service()->restituer($this->commande);
    }

    public function testLaRemiseEnStockNeReactiveJamaisUnProduitDesactiveMaisRemetEnVenteUnProduitEnRupture(): void
    {
        $this->a->setIsActive(false); // désactivé à la main par l'administrateur
        $this->b->setStock(0);        // simplement en rupture (actif)
        $this->assertFalse($this->b->isDisponible());

        $this->service()->restituer($this->commande);

        $this->assertSame(15, $this->a->getStock(), 'Le stock augmente...');
        $this->assertFalse($this->a->isDisponible(), '... mais le produit désactivé par l\'administrateur le reste');
        $this->assertSame(2, $this->b->getStock());
        $this->assertTrue($this->b->isDisponible(), 'Un produit en rupture revient en vente tout seul après réapprovisionnement');
    }

    public function testLaDisponibiliteEstCalculeeActifEtStock(): void
    {
        $desactive = (new Produit())->setName('X')->setPrix(1.0)->setStock(0)->setIsActive(false);
        $actifEnRupture = (new Produit())->setName('Y')->setPrix(1.0)->setStock(0);
        $actifEnStock = (new Produit())->setName('Z')->setPrix(1.0)->setStock(3);

        $this->assertFalse($desactive->isDisponible());
        $this->assertFalse($actifEnRupture->isDisponible());
        $this->assertTrue($actifEnRupture->isEnRupture());
        $this->assertTrue($actifEnStock->isDisponible());
        $this->assertFalse($desactive->isEnRupture(), 'Un produit désactivé n\'est pas « en rupture »');

        $desactive->incrementerStock(5);
        $actifEnRupture->incrementerStock(5);
        $this->assertFalse($desactive->isDisponible(), 'Réapprovisionner ne réactive jamais un produit désactivé');
        $this->assertTrue($actifEnRupture->isDisponible(), 'Réapprovisionner remet en vente un produit actif');
    }

    public function testLeRetraitDeStockAZeroNEcritJamaisIndisponible(): void
    {
        $p = (new Produit())->setName('W')->setPrix(1.0)->setStock(2);
        $p->decrémenterStock(2);

        $this->assertTrue($p->isActive(), 'Le réglage « actif » de l\'administrateur n\'est jamais modifié par le stock');
        $this->assertFalse($p->isDisponible());
        $p->incrementerStock(4);
        $this->assertTrue($p->isDisponible());
    }
}