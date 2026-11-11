<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Entity\CommandeHistorique;
use App\Entity\Produit;
use App\Enum\CommandeStatut;
use App\Service\RemboursementCommandeService;
use App\Service\StripeEvenements;
use App\Tests\Support\AvecWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Remboursements faits dans le tableau de bord Stripe (événement charge.refunded) : total, partiel, rejeu. */
class RemboursementCommandeServiceTest extends KernelTestCase
{
    use AvecWorkflow;

    private const PI = 'pi_test_123';

    private Commande $commande;
    private Produit $produit;
    /** @var list<object> */
    private array $persistes = [];
    private int $critiques = 0;
    private int $flushs = 0;
    private ?string $sessionDuFallback = null;

    protected function setUp(): void
    {
        $this->produit = (new Produit())->setName('Cookie A')->setPrix(3.0)->setStock(40)->setDisponible(true);
        $this->commande = (new Commande())->setNumeroCommande('PER-T')->setTotalTTC(12.38)->setStripeSessionId('cs_1')
            ->setStripePaymentIntentId(self::PI)->setStatut(CommandeStatut::PAYEE);
        $this->persistes = [];
        $this->critiques = 0;
        $this->flushs = 0;
        $this->sessionDuFallback = null;
    }

    private function service(bool $parPaymentIntent = true): RemboursementCommandeService
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteres) use ($parPaymentIntent) {
            if (isset($criteres['stripePaymentIntentId'])) {
                return $parPaymentIntent && $criteres['stripePaymentIntentId'] === self::PI ? $this->commande : null;
            }

            return ($criteres['stripeSessionId'] ?? null) === 'cs_1' ? $this->commande : null;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f) => $f());
        $em->method('getRepository')->willReturn($repository);
        $em->method('persist')->willReturnCallback(function (object $o): void { $this->persistes[] = $o; });
        $em->method('flush')->willReturnCallback(function (): void { $this->flushs++; });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('critical')->willReturnCallback(function (): void { $this->critiques++; });

        /** @var StripeEvenements&MockObject $stripe */
        $stripe = $this->createMock(StripeEvenements::class);
        $stripe->method('sessionDuPaiement')->willReturnCallback(fn () => $this->sessionDuFallback);

        return new RemboursementCommandeService($em, $logger, $this->changementStatut(), $stripe);
    }

    private function notes(): array
    {
        return array_values(array_map(static fn (CommandeHistorique $h) => $h->getNote(), array_filter($this->persistes, static fn ($o) => $o instanceof CommandeHistorique)));
    }

    public function testRemboursementTotalPasseLaCommandeEnRembourseeSansToucherAuStock(): void
    {
        [$resultat, $commande] = $this->service()->enregistrer(self::PI, 1238, true);

        $this->assertSame(RemboursementCommandeService::REMBOURSEE, $resultat);
        $this->assertSame(CommandeStatut::REMBOURSEE, $commande->getStatut());
        $this->assertEqualsWithDelta(12.38, $commande->getMontantRembourse(), 0.001);
        $this->assertSame(40, $this->produit->getStock(), 'Le stock n\'est JAMAIS remis automatiquement');
        $this->assertSame(0, $this->critiques);
    }

    public function testRemboursementPartielEnregistreLeMontantEtUneNoteSansChangerLeStatut(): void
    {
        [$resultat, $commande] = $this->service()->enregistrer(self::PI, 500, false);

        $this->assertSame(RemboursementCommandeService::REMBOURSEMENT_PARTIEL, $resultat);
        $this->assertSame(CommandeStatut::PAYEE, $commande->getStatut(), 'Un remboursement partiel ne termine pas la commande');
        $this->assertEqualsWithDelta(5.0, $commande->getMontantRembourse(), 0.001);
        $this->assertStringContainsString('Remboursement partiel dans Stripe : 5,00 €', $this->notes()[0]);
    }

    public function testPartielPuisTotalAboutitARembourseeAvecLeBonMontant(): void
    {
        $service = $this->service();

        $service->enregistrer(self::PI, 500, false);
        [$resultat, $commande] = $service->enregistrer(self::PI, 1238, true);

        $this->assertSame(RemboursementCommandeService::REMBOURSEE, $resultat);
        $this->assertSame(CommandeStatut::REMBOURSEE, $commande->getStatut());
        $this->assertEqualsWithDelta(12.38, $commande->getMontantRembourse(), 0.001);
    }

    public function testEvenementRejoueNeChangeRien(): void
    {
        $service = $this->service();
        $service->enregistrer(self::PI, 500, false);
        $notesAvant = count($this->notes());
        $flushsAvant = $this->flushs;

        [$resultat] = $service->enregistrer(self::PI, 500, false);

        $this->assertSame(RemboursementCommandeService::DEJA_TRAITEE, $resultat);
        $this->assertCount($notesAvant, $this->notes(), 'Pas de seconde note');
        $this->assertSame($flushsAvant, $this->flushs);
    }

    public function testTotalRejoueEtAncienPartielArriveApresSontIgnores(): void
    {
        $service = $this->service();
        $service->enregistrer(self::PI, 1238, true);

        $this->assertSame(RemboursementCommandeService::DEJA_TRAITEE, $service->enregistrer(self::PI, 1238, true)[0]);
        $this->assertSame(RemboursementCommandeService::DEJA_TRAITEE, $service->enregistrer(self::PI, 500, false)[0], 'Événement ancien arrivé en retard');
        $this->assertEqualsWithDelta(12.38, $this->commande->getMontantRembourse(), 0.001, 'Le montant ne redescend jamais');
        $this->assertSame(CommandeStatut::REMBOURSEE, $this->commande->getStatut());
    }

    public function testCommandeAnterieureAuSuiviDuPaiementRetrouveeParLaSessionStripe(): void
    {
        $this->commande->setStripePaymentIntentId(null);
        $this->sessionDuFallback = 'cs_1';

        [$resultat, $commande] = $this->service(false)->enregistrer(self::PI, 1238, true);

        $this->assertSame(RemboursementCommandeService::REMBOURSEE, $resultat);
        $this->assertSame(self::PI, $commande->getStripePaymentIntentId(), 'L\'identifiant est mémorisé pour la suite');
    }

    public function testPaiementInconnuEstJournaliseEnCritique(): void
    {
        $this->sessionDuFallback = null;

        [$resultat, $commande] = $this->service(false)->enregistrer('pi_inconnu', 1000, true);

        $this->assertSame(RemboursementCommandeService::INTROUVABLE, $resultat);
        $this->assertNull($commande);
        $this->assertSame(1, $this->critiques);
    }

    public function testRemboursementTotalSurCommandeNonPayeeEstAVerifier(): void
    {
        $this->commande->setStatut(CommandeStatut::EN_ATTENTE);

        [, $commande] = $this->service()->enregistrer(self::PI, 1238, true);

        $this->assertSame(CommandeStatut::A_VERIFIER, $commande->getStatut());
        $this->assertSame(1, $this->critiques);
    }

    public function testRemboursementSurCommandeLivreeEstPossible(): void
    {
        $this->commande->setStatut(CommandeStatut::LIVREE);

        [, $commande] = $this->service()->enregistrer(self::PI, 1238, true);

        $this->assertSame(CommandeStatut::REMBOURSEE, $commande->getStatut());
    }
}
