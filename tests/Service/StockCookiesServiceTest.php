<?php

namespace App\Tests\Service;

use App\Entity\Box;
use App\Entity\BoxProduit;
use App\Entity\Commande;
use App\Entity\CompositionBoxPersonnalisable;
use App\Entity\CompositionPanierPersonnalisable;
use App\Entity\LigneCommande;
use App\Entity\LignePanier;
use App\Entity\Produit;
use App\Service\StockCookiesService;
use PHPUnit\Framework\TestCase;

class StockCookiesServiceTest extends TestCase
{
    private function cookie(string $nom, int $stock, bool $disponible = true): Produit
    {
        return (new Produit())->setName($nom)->setPrix(3.0)->setStock($stock)->setIsActive($disponible);
    }

    private function boxClassique(array $recette): Box
    {
        $box = new Box();
        foreach ($recette as [$produit, $quantite]) {
            $box->addComposition((new BoxProduit())->setProduit($produit)->setQuantite($quantite));
        }

        return $box;
    }

    private function quantite(array $besoins, Produit $produit): int
    {
        foreach ($besoins as $besoin) {
            if ($besoin['produit'] === $produit) {
                return $besoin['quantite'];
            }
        }

        return 0;
    }

    public function testBesoinsDuPanierCumulentCookiesSeulsBoxClassiqueEtBoxPerso(): void
    {
        $a = $this->cookie('A', 50);
        $b = $this->cookie('B', 50);

        $seul = (new LignePanier())->setProduit($a)->setQuantite(1);
        $classique = (new LignePanier())->setBox($this->boxClassique([[$a, 2], [$b, 1]]))->setQuantite(3);

        $perso = (new LignePanier())->setBox((new Box())->setType('personnalisable'))->setQuantite(1)->setTailleBox(6);
        $perso->addCompositionPanier((new CompositionPanierPersonnalisable())->setProduit($b)->setQuantite(4));
        $perso->addCompositionPanier((new CompositionPanierPersonnalisable())->setProduit($a)->setQuantite(2));

        $besoins = (new StockCookiesService())->besoinsDepuisPanier([$seul, $classique, $perso]);

        $this->assertSame(1 + 3 * 2 + 2, $this->quantite($besoins, $a), 'A : seul + 3 boxes x 2 + perso');
        $this->assertSame(3 * 1 + 4, $this->quantite($besoins, $b), 'B : 3 boxes x 1 + perso');
    }

    public function testLaCommandeUtiliseLaCompositionFigeeEtPasLaRecetteActuelle(): void
    {
        $a = $this->cookie('A', 50);
        $recette = $this->boxClassique([[$a, 2]]);

        // Composition figée à la commande : 2 x A par box
        $ligne = (new LigneCommande())->setBox($recette)->setQuantite(3)->setPrixUnitaire(10.0);
        $ligne->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($a)->setQuantite(2));
        $commande = (new Commande())->addLigneCommande($ligne);

        // L'admin modifie ensuite la recette : 2 -> 10 cookies A par box
        $recette->getCompositions()->first()->setQuantite(10);

        $besoins = (new StockCookiesService())->besoinsDepuisCommande($commande);

        $this->assertSame(3 * 2, $this->quantite($besoins, $a), 'Les anciennes commandes ne changent pas');
    }

    public function testVerifierDonneUnMessagePrecisParCookieEnDefaut(): void
    {
        $a = $this->cookie('Cookie A', 2);
        $b = $this->cookie('Cookie B', 10);
        $c = $this->cookie('Cookie C', 10, false);
        $service = new StockCookiesService();

        $messages = $service->verifier($service->besoinsDepuisPanier([
            (new LignePanier())->setProduit($a)->setQuantite(3),
            (new LignePanier())->setProduit($b)->setQuantite(10),
            (new LignePanier())->setProduit($c)->setQuantite(1),
        ]));

        $this->assertSame([
            'Stock insuffisant pour Cookie A : 3 demandé(s), 2 disponible(s).',
            'Cookie C n\'est plus disponible.',
        ], $messages);
    }

    public function testStockExactEstAccepte(): void
    {
        $a = $this->cookie('A', 4);
        $service = new StockCookiesService();

        $this->assertSame([], $service->verifier($service->besoinsDepuisPanier([(new LignePanier())->setProduit($a)->setQuantite(4)])));
    }

    public function testProduitRefuseUnStockNegatif(): void
    {
        $this->expectException(\DomainException::class);

        $this->cookie('A', 1)->decrémenterStock(2);
    }

    public function testStockDisponibleDUneBoxVientDuCookieLimitant(): void
    {
        $a = $this->cookie('A', 9);   // 9 / 2 = 4 boxes
        $b = $this->cookie('B', 3);   // 3 / 1 = 3 boxes  <- limitant
        $box = $this->boxClassique([[$a, 2], [$b, 1]]);

        $this->assertSame(3, $box->getStockDisponible());
        $this->assertSame(0, (new Box())->getStockDisponible(), 'Recette vide : rien à vendre');
    }
}
