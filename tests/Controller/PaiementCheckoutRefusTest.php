<?php

namespace App\Tests\Controller;

use App\Controller\PaiementController;
use App\Entity\Adresse;
use App\Entity\Box;
use App\Entity\BoxProduit;
use App\Entity\LignePanier;
use App\Entity\Panier;
use App\Entity\Produit;
use App\Service\StockCookiesService;
use App\Entity\User;
use App\Repository\AdresseRepository;
use App\Repository\LignePanierRepository;
use App\Repository\PanierRepository;
use App\Service\CommandeService;
use App\Service\FraisLivraisonService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * create-checkout-session : les refus (400) doivent arriver AVANT toute création de commande ou
 * d'appel à Stripe. Tout est simulé : ni base de données, ni réseau.
 */
class PaiementCheckoutRefusTest extends TestCase
{
    private User $client;

    protected function setUp(): void
    {
        $this->client = $this->utilisateur(1);
    }

    private function utilisateur(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function adresseDe(User $proprietaire, int $id): Adresse
    {
        $adresse = (new Adresse())->setUser($proprietaire);
        (new \ReflectionProperty(Adresse::class, 'id'))->setValue($adresse, $id);

        return $adresse;
    }

    /** Appelle le contrôleur ; renvoie [statut HTTP, charge utile JSON]. */
    private function appeler(string $corps, ?int $adresseEnSession, ?Adresse $adresseTrouvee = null, array $lignes = []): array
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($this->client, 'main', []));
        $conteneur = new Container();
        $conteneur->set('security.token_storage', $tokens);

        $controleur = new PaiementController(new FraisLivraisonService(), []);
        $controleur->setContainer($conteneur);

        $request = Request::create('/paiement/create-checkout-session', 'POST', [], [], [], [], $corps);
        $session = new Session(new MockArraySessionStorage());
        if ($adresseEnSession !== null) {
            $session->set('adresse_livraison_id', $adresseEnSession);
        }
        $request->setSession($session);

        $adresses = $this->createMock(AdresseRepository::class);
        $adresses->method('find')->willReturn($adresseTrouvee);

        // Aucune commande ne doit être créée, ni enregistrée
        $commandes = $this->createMock(CommandeService::class);
        $commandes->expects($this->never())->method('creerCommande');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $paniers = $this->createMock(PanierRepository::class);
        $paniers->method('findOneBy')->willReturn($lignes === [] ? null : new Panier());
        $lignesRepo = $this->createMock(LignePanierRepository::class);
        $lignesRepo->method('findBy')->willReturn($lignes);

        $reponse = $controleur->createCheckoutSession(
            $request,
            $paniers,
            $lignesRepo,
            $commandes,
            $adresses,
            new StockCookiesService(),
            $em
        );

        return [$reponse->getStatusCode(), json_decode($reponse->getContent(), true)];
    }

    public function testLivraisonSansAdresseEstRefusee(): void
    {
        [$statut, $json] = $this->appeler('{"mode":"livraison"}', null);

        $this->assertSame(400, $statut);
        $this->assertStringContainsString('adresse', $json['error']);
    }

    public function testAdresseDUnAutreClientEstRefusee(): void
    {
        $autre = $this->utilisateur(2);

        [$statut, $json] = $this->appeler('{"mode":"livraison"}', 77, $this->adresseDe($autre, 77));

        $this->assertSame(400, $statut);
        $this->assertStringContainsString('adresse', $json['error']);
    }

    public function testAdresseInexistanteEstRefusee(): void
    {
        [$statut] = $this->appeler('{"mode":"livraison"}', 999, null);

        $this->assertSame(400, $statut);
    }

    public function testModeInconnuEstRefuse(): void
    {
        [$statut, $json] = $this->appeler('{"mode":"drone"}', 5, $this->adresseDe($this->client, 5));

        $this->assertSame(400, $statut);
        $this->assertSame('Mode de livraison invalide', $json['error']);
    }

    private function cookie(string $nom, int $stock): Produit
    {
        return (new Produit())->setName($nom)->setPrix(3.0)->setStock($stock)->setDisponible(true);
    }

    public function testStockInsuffisantEstBloqueAvecLeNomDuCookieSansCreerDeCommande(): void
    {
        $macadamia = $this->cookie('Cookie Macadamia', 2);
        $ligne = (new LignePanier())->setProduit($macadamia)->setQuantite(3);

        [$statut, $json] = $this->appeler('{"mode":"retrait"}', null, null, [$ligne]);

        $this->assertSame(409, $statut);
        $this->assertSame('Stock insuffisant pour Cookie Macadamia : 3 demandé(s), 2 disponible(s).', $json['error']);
    }

    public function testLesCookiesSeulsEtCeuxDesBoxesSontCumulesAvantDeBloquer(): void
    {
        // Stock 5 : 2 cookies seuls + 2 boxes x 2 cookies = 6 demandés
        $pistache = $this->cookie('Cookie Pistache', 5);
        $box = (new Box())->setPrix(19.9);
        $box->addComposition((new BoxProduit())->setProduit($pistache)->setQuantite(2));
        $seul = (new LignePanier())->setProduit($pistache)->setQuantite(2);
        $laBox = (new LignePanier())->setBox($box)->setQuantite(2);

        [$statut, $json] = $this->appeler('{"mode":"retrait"}', null, null, [$seul, $laBox]);

        $this->assertSame(409, $statut);
        $this->assertStringContainsString('Cookie Pistache : 6 demandé(s), 5 disponible(s)', $json['error']);
    }

    public function testBoxDesactiveeEstBlockeeAuClicSurPayerSansCreerDeCommande(): void
    {
        $box = (new Box())->setNom('Box Gourmande')->setType('fixe')->setPrix(24.9)->setIsActive(false);
        $ligne = (new LignePanier())->setBox($box)->setQuantite(1);

        [$statut, $json] = $this->appeler('{"mode":"retrait"}', null, null, [$ligne]);

        $this->assertSame(409, $statut);
        $this->assertSame('La box « Box Gourmande » n\'est plus disponible.', $json['error']);
    }

    public function testBoxClassiqueSansRecetteNEstPasVendue(): void
    {
        $box = (new Box())->setNom('Box Vide')->setType('fixe')->setPrix(19.9);
        $ligne = (new LignePanier())->setBox($box)->setQuantite(1);

        [$statut, $json] = $this->appeler('{"mode":"retrait"}', null, null, [$ligne]);

        $this->assertSame(409, $statut);
        $this->assertStringContainsString('Box Vide', $json['error']);
    }

    public function testBoxPersoSansPrixPourSaTailleBloqueLePaiementSansCreerDeCommande(): void
    {
        $box = (new Box())->setNom('Box Personnalisable')->setType('personnalisable')->setPrix(19.90)->setPrix6(12.99)->setPrix12(null)->setPrix24(29.99);
        $ligne = (new LignePanier())->setBox($box)->setQuantite(1)->setTailleBox(12);

        [$statut, $json] = $this->appeler('{"mode":"retrait"}', null, null, [$ligne]);

        $this->assertSame(409, $statut);
        $this->assertStringContainsString('Le prix de « Box Personnalisable » n\'est pas disponible', $json['error']);
    }
}