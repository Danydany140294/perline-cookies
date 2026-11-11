<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Entity\User;
use App\Enum\CommandeStatut;
use App\Service\PaiementCommandeService;
use App\Service\StockCookiesService;
use App\Service\TraitementEvenementStripe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

/**
 * Traitement d'un événement Stripe authentifié (webhook ou rejeu) : idempotent, sans base ni réseau.
 */
class TraitementEvenementStripeTest extends TestCase
{
    private const SESSION = 'cs_test_abc';

    private Commande $commande;
    private Produit $produit;
    private int $emailsEnvoyes = 0;
    private int $critiques = 0;

    protected function setUp(): void
    {
        $this->produit = (new Produit())->setName('Cookie A')->setPrix(3.0)->setStock(10)->setDisponible(true);
        $this->commande = (new Commande())->setNumeroCommande('CMD-T')->setTotalTTC(10.5)->setStripeSessionId(self::SESSION)->setUser((new User())->setEmail('client@example.com'));
        $this->commande->addLigneCommande((new LigneCommande())->setProduit($this->produit)->setQuantite(2)->setPrixUnitaire(3.0));
        $this->emailsEnvoyes = 0;
        $this->critiques = 0;
    }

    private function traitement(): TraitementEvenementStripe
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f) => $f());
        $em->method('find')->willReturn($this->commande);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('critical')->willReturnCallback(function (): void { $this->critiques++; });

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (): void { $this->emailsEnvoyes++; });
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<p>ok</p>');

        return new TraitementEvenementStripe(
            new PaiementCommandeService($em, $logger, new StockCookiesService()),
            $mailer,
            $twig,
            $logger,
            []
        );
    }

    private function evenement(string $type, array $session = []): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id' => 'evt_test_1',
            'type' => $type,
            'data' => ['object' => $session + [
                'id' => self::SESSION,
                'client_reference_id' => '1',
                'payment_status' => 'paid',
                'currency' => 'eur',
                'amount_total' => 1050,
            ]],
        ]);
    }

    public function testPaiementEstTraiteUneSeuleFoisMemeRejoue(): void
    {
        $traitement = $this->traitement();
        $evenement = $this->evenement('checkout.session.completed');

        $this->assertSame(PaiementCommandeService::PAYEE, $traitement->traiter($evenement));
        $this->assertSame(8, $this->produit->getStock());
        $this->assertSame(1, $this->emailsEnvoyes);

        // Rejeu du même événement (webhook rejoué par Stripe, ou commande de rattrapage)
        $this->assertSame(PaiementCommandeService::DEJA_TRAITEE, $traitement->traiter($evenement));
        $this->assertSame(8, $this->produit->getStock(), 'Le stock n\'est jamais retiré deux fois');
        $this->assertSame(1, $this->emailsEnvoyes, 'Pas de second e-mail');
        $this->assertSame(CommandeStatut::PAYEE, $this->commande->getStatut());
    }

    public function testSessionExpireeAnnuleUneFoisEtLeRejeuNeChangeRien(): void
    {
        $traitement = $this->traitement();
        $evenement = $this->evenement('checkout.session.expired', ['payment_status' => 'unpaid']);

        $this->assertSame(PaiementCommandeService::ANNULEE, $traitement->traiter($evenement));
        $this->assertSame(PaiementCommandeService::DEJA_TRAITEE, $traitement->traiter($evenement));
        $this->assertSame(CommandeStatut::ANNULEE, $this->commande->getStatut());
        $this->assertSame(10, $this->produit->getStock());
        $this->assertSame(0, $this->emailsEnvoyes);
    }

    public function testEcartDeMontantRejoueResteAVerifier(): void
    {
        $traitement = $this->traitement();
        $evenement = $this->evenement('checkout.session.completed', ['amount_total' => 1]);

        $this->assertSame(PaiementCommandeService::A_VERIFIER, $traitement->traiter($evenement));
        $this->assertSame(PaiementCommandeService::DEJA_TRAITEE, $traitement->traiter($evenement));
        $this->assertSame(CommandeStatut::A_VERIFIER, $this->commande->getStatut());
        $this->assertSame(10, $this->produit->getStock());
        $this->assertSame(1, $this->critiques, 'Un seul log critique, pas un par rejeu');
    }

    public function testPaiementSansReferenceNeCreeAucuneCommande(): void
    {
        $traitement = $this->traitement();

        $this->assertSame(
            TraitementEvenementStripe::SANS_REFERENCE,
            $traitement->traiter($this->evenement('checkout.session.completed', ['client_reference_id' => null]))
        );
        $this->assertSame(1, $this->critiques);
        $this->assertSame(CommandeStatut::EN_ATTENTE, $this->commande->getStatut());
    }

    public function testAutreTypeDEvenementEstIgnore(): void
    {
        $this->assertSame(TraitementEvenementStripe::IGNORE, $this->traitement()->traiter($this->evenement('charge.refunded')));
    }
}

