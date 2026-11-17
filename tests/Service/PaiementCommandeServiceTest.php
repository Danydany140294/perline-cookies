<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\LignePanier;
use App\Entity\CompositionPanierPersonnalisable;
use App\Entity\Panier;
use App\Entity\Produit;
use App\Entity\User;
use App\Enum\CommandeStatut;
use App\Entity\Box;
use App\Entity\CompositionBoxPersonnalisable;
use App\Service\PaiementCommandeService;
use App\Service\StockCookiesService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use App\Tests\Support\AvecWorkflow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests du cœur du webhook Stripe, sans base de données ni réseau
 * (l'EntityManager est simulé : la base de dev n'est jamais touchée).
 */
class PaiementCommandeServiceTest extends KernelTestCase
{
    use AvecWorkflow;

    private const SESSION = 'cs_test_123';

    private Produit $produit;
    private Commande $commande;
    private ?Panier $panier = null;
    /** @var list<object> */
    private array $supprimes = [];
    private int $flushs = 0;

    private function service(?Commande $commande, bool $logCritique = false): PaiementCommandeService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f) => $f());
        $em->method('find')->willReturn($commande);
        $em->method('flush')->willReturnCallback(function (): void { $this->flushs++; });
        $em->method('remove')->willReturnCallback(function (object $o): void { $this->supprimes[] = $o; });

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($this->panier);
        $em->method('getRepository')->willReturn($repo);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($logCritique ? $this->atLeastOnce() : $this->never())->method('critical');

        return new PaiementCommandeService($em, $logger, new StockCookiesService(), $this->changementStatut());
    }

    protected function setUp(): void
    {
        $this->produit = (new Produit())->setName('Cookie test')->setPrix(4.34)->setStock(10);

        $this->commande = new Commande(); // statut EN_ATTENTE par défaut
        $this->commande->setNumeroCommande('CMD-TEST')
            ->setTotalTTC(12.38) // 2 x 3,94 + 4,50
            ->setStripeSessionId(self::SESSION)
            ->setUser(new User());

        $ligne = (new LigneCommande())->setProduit($this->produit)->setQuantite(2)->setPrixUnitaire(3.94);
        $this->commande->addLigneCommande($ligne);

        $this->panier = new Panier();
        $this->panier->setUser($this->commande->getUser());
    }

    public function testPaiementConformeEstPayeeStockDecrementeEtPanierVide(): void
    {
        [$resultat] = $this->service($this->commande)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::PAYEE, $resultat);
        $this->assertSame(CommandeStatut::PAYEE, $this->commande->getStatut());
        $this->assertSame(8, $this->produit->getStock());
        $this->assertContains($this->panier, $this->supprimes);
    }

    public function testMontantDifferentEstAVerifierSansToucherStockNiPanier(): void
    {
        [$resultat] = $this->service($this->commande, true)->finaliser(1, self::SESSION, 'paid', 'eur', 1138);

        $this->assertSame(PaiementCommandeService::A_VERIFIER, $resultat);
        $this->assertSame(CommandeStatut::A_VERIFIER, $this->commande->getStatut());
        $this->assertSame(10, $this->produit->getStock());
        $this->assertSame([], $this->supprimes);
    }

    public function testDeviseInattendueEstAVerifier(): void
    {
        [$resultat] = $this->service($this->commande, true)->finaliser(1, self::SESSION, 'paid', 'usd', 1238);

        $this->assertSame(PaiementCommandeService::A_VERIFIER, $resultat);
        $this->assertSame(10, $this->produit->getStock());
    }

    public function testSessionStripeDifferenteEstAVerifier(): void
    {
        [$resultat] = $this->service($this->commande, true)->finaliser(1, 'cs_autre', 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::A_VERIFIER, $resultat);
    }

    public function testPaiementNonPayeNeChangeRien(): void
    {
        [$resultat] = $this->service($this->commande)->finaliser(1, self::SESSION, 'unpaid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::NON_PAYEE, $resultat);
        $this->assertSame(CommandeStatut::EN_ATTENTE, $this->commande->getStatut());
        $this->assertSame(10, $this->produit->getStock());
        $this->assertSame(0, $this->flushs);
    }

    public function testIdempotenceDeuxiemeLivraisonNeDecrementePasDeuxFois(): void
    {
        $service = $this->service($this->commande);

        $service->finaliser(1, self::SESSION, 'paid', 'eur', 1238);
        [$resultat] = $service->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::DEJA_TRAITEE, $resultat);
        $this->assertSame(8, $this->produit->getStock());
    }

    public function testCommandeAVerifierNeSeRetraitePas(): void
    {
        $service = $this->service($this->commande, true);

        $service->finaliser(1, self::SESSION, 'paid', 'eur', 1);
        [$resultat] = $service->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::DEJA_TRAITEE, $resultat);
        $this->assertSame(CommandeStatut::A_VERIFIER, $this->commande->getStatut());
    }

    public function testCommandeIntrouvableEstJournaliseeEnCritique(): void
    {
        [$resultat, $commande] = $this->service(null, true)->finaliser(999, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::INTROUVABLE, $resultat);
        $this->assertNull($commande);
    }

    // ───────────── Point D : stock des cookies, boxes comprises ─────────────

    private function ligneBoxFigee(int $quantiteBox, array $recette): LigneCommande
    {
        $ligne = (new LigneCommande())->setBox(new Box())->setQuantite($quantiteBox)->setPrixUnitaire(19.9);
        foreach ($recette as [$produit, $quantite]) {
            $ligne->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($produit)->setQuantite($quantite));
        }

        return $ligne;
    }

    public function testUneBoxRetireLesCookiesDeSaRecetteFigeeMultiplieParLaQuantite(): void
    {
        // 1 cookie A seul + 2 boxes contenant chacune 2 A et 1 B  =>  A : 1 + 4 = 5, B : 2
        $this->commande->addLigneCommande($this->ligneBoxFigee(2, [[$this->produit, 2], [$this->autre(), 1]]));
        $b = $this->commande->getLignesCommande()->last()->getCompositionsBox()->last()->getProduit();
        $this->commande->setTotalTTC(12.38);

        [$resultat] = $this->service($this->commande)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::PAYEE, $resultat);
        $this->assertSame(10 - 2 - 4, $this->produit->getStock(), 'A : 2 seul + 2 x 2 par les boxes');
        $this->assertSame(10 - 2, $b->getStock(), 'B : 2 boxes x 1');
    }

    public function testStockInsuffisantMemeAvecLesBoxesPasseEnAVerifierSansToucherAuStock(): void
    {
        $b = $this->autre();
        // A : 2 seul + 3 boxes x 2 = 8 (stock 10 ok) ; B : 3 boxes x 4 = 12 (stock 10 : manque)
        $this->commande->addLigneCommande($this->ligneBoxFigee(3, [[$this->produit, 2], [$b, 4]]));

        [$resultat] = $this->service($this->commande, true)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::A_VERIFIER, $resultat);
        $this->assertSame(CommandeStatut::A_VERIFIER, $this->commande->getStatut());
        $this->assertSame(10, $this->produit->getStock(), 'Rien n\'est retiré (tout ou rien)');
        $this->assertSame(10, $b->getStock());
        $this->assertSame([], $this->supprimes, 'Le panier est conservé');
    }

    public function testUneExceptionDeProduitNeFaitPasPlanterLeWebhook(): void
    {
        // Cas limite : la vérification passe, mais Produit refuse (stock lu différemment sous verrou)
        $instable = new class extends Produit {
            public function decrémenterStock(int $quantite = 1): static
            {
                throw new \DomainException('Stock insuffisant (simulé).');
            }
        };
        $instable->setName('Instable')->setPrix(1.0)->setStock(10);
        $this->commande->addLigneCommande((new LigneCommande())->setProduit($instable)->setQuantite(1)->setPrixUnitaire(1.0));

        [$resultat] = $this->service($this->commande, true)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::A_VERIFIER, $resultat, 'Pas d\'exception, pas de 500 : réponse normale');
        $this->assertSame(CommandeStatut::A_VERIFIER, $this->commande->getStatut());
        $this->assertSame([], $this->supprimes);
    }

    private function autre(): Produit
    {
        return (new Produit())->setName('B')->setPrix(2.0)->setStock(10);
    }

    // ───────────── Scénarios de paiement : session expirée, paiement tardif ─────────────

    public function testSessionExpireeAnnuleLaCommandeEnAttenteSansLaSupprimer(): void
    {
        [$resultat] = $this->service($this->commande)->annulerSessionExpiree(1, self::SESSION);

        $this->assertSame(PaiementCommandeService::ANNULEE, $resultat);
        $this->assertSame(CommandeStatut::ANNULEE, $this->commande->getStatut());
        $this->assertSame([], $this->supprimes, 'Annulée, jamais supprimée');
        $this->assertSame(10, $this->produit->getStock(), 'Le stock n\'a jamais été touché');
    }

    public function testSessionExpireeNeToucheJamaisUneCommandePayeeOuAVerifier(): void
    {
        foreach ([CommandeStatut::PAYEE, CommandeStatut::A_VERIFIER] as $statut) {
            $this->commande->setStatut($statut);

            [$resultat] = $this->service($this->commande)->annulerSessionExpiree(1, self::SESSION);

            $this->assertSame(PaiementCommandeService::DEJA_TRAITEE, $resultat);
            $this->assertSame($statut, $this->commande->getStatut());
        }
    }

    public function testSessionExpireeDUneAutreSessionEstIgnoree(): void
    {
        [$resultat] = $this->service($this->commande)->annulerSessionExpiree(1, 'cs_autre');

        $this->assertSame(PaiementCommandeService::IGNOREE, $resultat);
        $this->assertSame(CommandeStatut::EN_ATTENTE, $this->commande->getStatut());
    }

    public function testSessionExpireeRejoueeDeuxFoisResteAnnulee(): void
    {
        $service = $this->service($this->commande);

        $service->annulerSessionExpiree(1, self::SESSION);
        [$resultat] = $service->annulerSessionExpiree(1, self::SESSION);

        $this->assertSame(PaiementCommandeService::DEJA_TRAITEE, $resultat);
        $this->assertSame(CommandeStatut::ANNULEE, $this->commande->getStatut());
    }

    public function testPaiementTardifSurCommandeAnnuleeEstAVerifierEtJamaisIgnore(): void
    {
        $this->commande->setStatut(CommandeStatut::ANNULEE);

        [$resultat] = $this->service($this->commande, true)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::A_VERIFIER, $resultat);
        $this->assertSame(CommandeStatut::A_VERIFIER, $this->commande->getStatut());
        $this->assertSame(10, $this->produit->getStock(), 'Rien n\'est retiré tant qu\'un humain n\'a pas vérifié');
        $this->assertSame([], $this->supprimes);
    }

    // ───────────── Panier : seuls les articles payés en sortent ─────────────

    private function lignePanier(Produit $produit, int $quantite): LignePanier
    {
        $ligne = (new LignePanier())->setProduit($produit)->setQuantite($quantite);
        $this->panier->addLignePanier($ligne);

        return $ligne;
    }

    public function testSeulsLesArticlesPayesSortentDuPanier(): void
    {
        // Commande : 2 × A. Panier au moment du paiement : 3 × A, plus 1 × B ajouté dans un autre onglet.
        $ligneA = $this->lignePanier($this->produit, 3);
        $ligneB = $this->lignePanier($this->autre(), 1);

        [$resultat] = $this->service($this->commande)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(PaiementCommandeService::PAYEE, $resultat);
        $this->assertSame(1, $ligneA->getQuantite(), '3 - 2 payés = 1 reste');
        $this->assertSame(1, $ligneB->getQuantite(), 'L\'article ajouté après le clic reste dans le panier');
        $this->assertNotContains($this->panier, $this->supprimes, 'Le panier n\'est pas vide : il n\'est pas supprimé');
        $this->assertNotContains($ligneB, $this->supprimes);
    }

    public function testPanierIdentiqueALaCommandeEstVideCompletement(): void
    {
        $ligneA = $this->lignePanier($this->produit, 2);

        $this->service($this->commande)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertContains($ligneA, $this->supprimes);
        $this->assertContains($this->panier, $this->supprimes);
    }

    public function testBoxClassiquePayeeNeRetireQueLaQuantiteCommandee(): void
    {
        $box = new Box();
        $this->commande->addLigneCommande((new LigneCommande())->setBox($box)->setQuantite(2)->setPrixUnitaire(19.9));
        $ligneBox = (new LignePanier())->setBox($box)->setQuantite(3);
        $this->panier->addLignePanier($ligneBox);
        $this->lignePanier($this->produit, 2);

        $this->service($this->commande)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertSame(1, $ligneBox->getQuantite());
        $this->assertNotContains($this->panier, $this->supprimes);
    }

    public function testBoxPersoSeRetireSeulementSiLaCompositionEstIdentique(): void
    {
        $perso = (new Box())->setType('personnalisable');
        $b = $this->autre();

        // Commande : box perso 4 × A + 2 × B
        $ligneCommande = (new LigneCommande())->setBox($perso)->setQuantite(1)->setPrixUnitaire(12.0);
        $ligneCommande->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($this->produit)->setQuantite(4));
        $ligneCommande->addCompositionBox((new CompositionBoxPersonnalisable())->setProduit($b)->setQuantite(2));
        $this->commande->addLigneCommande($ligneCommande);

        $identique = (new LignePanier())->setBox($perso)->setQuantite(1)->setTailleBox(6);
        $identique->addCompositionPanier((new CompositionPanierPersonnalisable())->setProduit($this->produit)->setQuantite(4));
        $identique->addCompositionPanier((new CompositionPanierPersonnalisable())->setProduit($b)->setQuantite(2));
        $differente = (new LignePanier())->setBox($perso)->setQuantite(1)->setTailleBox(6);
        $differente->addCompositionPanier((new CompositionPanierPersonnalisable())->setProduit($this->produit)->setQuantite(6));
        $this->panier->addLignePanier($identique);
        $this->panier->addLignePanier($differente);
        $this->lignePanier($this->produit, 2);

        $this->service($this->commande)->finaliser(1, self::SESSION, 'paid', 'eur', 1238);

        $this->assertContains($identique, $this->supprimes, 'La box perso payée sort du panier');
        $this->assertNotContains($differente, $this->supprimes, 'Une autre composition reste');
    }
}