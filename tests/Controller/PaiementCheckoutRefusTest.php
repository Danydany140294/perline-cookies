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
use App\Service\ZoneLivraison;
use App\Entity\User;
use App\Repository\AdresseRepository;
use App\Repository\LignePanierRepository;
use App\Repository\PanierRepository;
use App\Service\CommandeService;
use App\Service\FraisLivraisonService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    private function appeler(string $corps, ?int $adresseEnSession, ?Adresse $adresseTrouvee = null, array $lignes = [], ?\Throwable $echecCreation = null, ?FraisLivraisonService $frais = null): array
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($this->client, 'main', []));
        $conteneur = new Container();
        $conteneur->set('security.token_storage', $tokens);

        $controleur = new PaiementController($frais ?? new FraisLivraisonService(), new ZoneLivraison(), []);
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
        if ($echecCreation !== null) {
            $commandes->method('creerCommande')->willThrowException($echecCreation);
        } else {
            $commandes->expects($this->never())->method('creerCommande');
        }
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
            $em,
            new NullLogger()
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

    public function testUneErreurTechniqueNeFuiteJamaisVersLeNavigateur(): void
    {
        $cookie = (new Produit())->setName('Cookie A')->setPrix(3.0)->setStock(10)->setDisponible(true);
        $ligne = (new LignePanier())->setProduit($cookie)->setQuantite(1);

        [$statut, $json] = $this->appeler('{"mode":"retrait"}', null, null, [$ligne], new \RuntimeException('SQLSTATE[23000]: Duplicate entry secret-interne'));

        $this->assertSame(500, $statut);
        $this->assertStringNotContainsString('SQLSTATE', $json['error']);
        $this->assertStringNotContainsString('secret-interne', $json['error']);
        $this->assertStringContainsString('Merci de réessayer', $json['error']);
    }

    /** @dataProvider adressesHorsZone */
    public function testAdresseHorsZoneEstRefuseeAvecUnMessageClairSansCreerDeCommande(string $codePostal, string $pays, string $messageContient): void
    {
        $adresse = $this->adresseDe($this->client, 5)->setCodePostal($codePostal)->setPays($pays);

        [$statut, $json] = $this->appeler('{"mode":"livraison"}', 5, $adresse);

        $this->assertSame(400, $statut);
        $this->assertStringStartsWith('Cette adresse n\'est pas desservie : ', $json['error']);
        $this->assertStringContainsString($messageContient, $json['error']);
        $this->assertTrue($json['adresse_non_desservie']);
    }

    public static function adressesHorsZone(): iterable
    {
        yield 'Corse' => ['20000', 'France', 'Corse'];
        yield 'Haute-Corse' => ['20200', 'France', 'Corse'];
        yield 'Guadeloupe' => ['97100', 'France', 'outre-mer'];
        yield 'Polynésie' => ['98700', 'France', 'outre-mer'];
        yield 'Belgique' => ['1000', 'Belgique', 'France métropolitaine'];
        yield 'pays Autre' => ['34000', 'Autre', 'France métropolitaine'];
    }

    public function testRetraitDesactiveEstRefuseParLeServeur(): void
    {
        $sansRetrait = new FraisLivraisonService(['modes' => ['livraison' => true, 'retrait' => false], 'frais_forfait_centimes' => 450, 'seuil_gratuite_centimes' => 5000]);

        [$statut, $json] = $this->appeler('{"mode":"retrait"}', null, null, [], null, $sansRetrait);

        $this->assertSame(400, $statut);
        $this->assertSame('Ce mode de réception n\'est pas proposé pour le moment.', $json['error']);
    }

    public function testLivraisonDesactiveeEstRefuseeParLeServeur(): void
    {
        $sansLivraison = new FraisLivraisonService(['modes' => ['livraison' => false, 'retrait' => true], 'frais_forfait_centimes' => 450, 'seuil_gratuite_centimes' => 5000]);

        [$statut, $json] = $this->appeler('{"mode":"livraison"}', 5, $this->adresseDe($this->client, 5)->setCodePostal('34000')->setPays('France'), [], null, $sansLivraison);

        $this->assertSame(400, $statut);
        $this->assertSame('Ce mode de réception n\'est pas proposé pour le moment.', $json['error']);
    }
}