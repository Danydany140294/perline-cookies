<?php

namespace App\Tests\Controller;

use App\Controller\PaiementController;
use App\Entity\Adresse;
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
    private function appeler(string $corps, ?int $adresseEnSession, ?Adresse $adresseTrouvee = null): array
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

        $reponse = $controleur->createCheckoutSession(
            $request,
            $this->createMock(PanierRepository::class),
            $this->createMock(LignePanierRepository::class),
            $commandes,
            $adresses,
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
}
