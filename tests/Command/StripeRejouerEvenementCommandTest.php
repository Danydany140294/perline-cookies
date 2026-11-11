<?php

namespace App\Tests\Command;

use App\Command\StripeRejouerEvenementCommand;
use App\Service\PaiementCommandeService;
use App\Service\StripeEvenements;
use App\Service\TraitementEvenementStripe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class StripeRejouerEvenementCommandTest extends TestCase
{
    private function evenement(string $type = 'checkout.session.completed'): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id' => 'evt_123',
            'type' => $type,
            'data' => ['object' => ['id' => 'cs_1', 'client_reference_id' => '7', 'payment_status' => 'paid', 'currency' => 'eur', 'amount_total' => 1238]],
        ]);
    }

    private function testeur(?\Stripe\Event $event, string $resultat = PaiementCommandeService::PAYEE, bool $traiteAttendu = true, ?\Throwable $erreur = null, bool $recuperationAttendue = true): CommandTester
    {
        $stripe = $this->createMock(StripeEvenements::class);
        $methode = $stripe->expects($recuperationAttendue ? $this->once() : $this->never())->method('recuperer');
        if ($erreur !== null) {
            $methode->willThrowException($erreur);
        } elseif ($event !== null) {
            $methode->willReturn($event);
        }

        $traitement = $this->createMock(TraitementEvenementStripe::class);
        $traitement->expects($traiteAttendu ? $this->once() : $this->never())->method('traiter')->willReturn($resultat);

        return new CommandTester(new StripeRejouerEvenementCommand($stripe, $traitement));
    }

    public function testPaiementRejoueEstTraiteEtAffiche(): void
    {
        $testeur = $this->testeur($this->evenement(), PaiementCommandeService::PAYEE);

        $code = $testeur->execute(['evenement' => 'evt_123']);
        $this->assertSame(Command::SUCCESS, $code, $testeur->getDisplay());
        $this->assertStringContainsString('Commande payée', $testeur->getDisplay());
        $this->assertStringContainsString('cs_1', $testeur->getDisplay());
    }

    public function testEvenementDejaTraiteNeChangeRienEtLeDitClairement(): void
    {
        $testeur = $this->testeur($this->evenement(), PaiementCommandeService::DEJA_TRAITEE);

        $code = $testeur->execute(['evenement' => 'evt_123']);
        $this->assertSame(Command::SUCCESS, $code, $testeur->getDisplay());
        $this->assertStringContainsString('Déjà traité', $testeur->getDisplay());
    }

    public function testDryRunAfficheSansRienTraiter(): void
    {
        $testeur = $this->testeur($this->evenement(), PaiementCommandeService::PAYEE, false);

        $this->assertSame(Command::SUCCESS, $testeur->execute(['evenement' => 'evt_123', '--dry-run' => true]));
        $this->assertStringContainsString('rien n\'a été traité', $testeur->getDisplay());
    }

    public function testIdentifiantInvalideEstRefuseSansAppelerStripe(): void
    {
        $testeur = $this->testeur(null, PaiementCommandeService::PAYEE, false, null, false);

        $this->assertSame(Command::FAILURE, $testeur->execute(['evenement' => 'pas un id; DROP']));
        $this->assertStringContainsString('Identifiant invalide', $testeur->getDisplay());
    }

    public function testEvenementIntrouvableChezStripeEstUneErreur(): void
    {
        $testeur = $this->testeur(null, PaiementCommandeService::PAYEE, false, new \RuntimeException('No such event'));

        $this->assertSame(Command::FAILURE, $testeur->execute(['evenement' => 'evt_123']));
        $this->assertStringContainsString('No such event', $testeur->getDisplay());
    }

    public function testTypeNonGereEstRefuse(): void
    {
        $testeur = $this->testeur($this->evenement('customer.created'), PaiementCommandeService::PAYEE, false);

        $this->assertSame(Command::FAILURE, $testeur->execute(['evenement' => 'evt_123']));
        $this->assertStringContainsString('non géré', $testeur->getDisplay());
    }

    public function testCommandeIntrouvableEstUneErreur(): void
    {
        $testeur = $this->testeur($this->evenement(), PaiementCommandeService::INTROUVABLE);

        $this->assertSame(Command::FAILURE, $testeur->execute(['evenement' => 'evt_123']));
    }
}
