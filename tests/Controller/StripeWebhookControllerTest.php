<?php

namespace App\Tests\Controller;

use App\Controller\StripeWebhookController;
use App\Service\TraitementEvenementStripe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Le webhook vérifie la signature, puis délègue à TraitementEvenementStripe et répond toujours 200. */
class StripeWebhookControllerTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = self::SECRET;
    }

    private function requete(string $payload, ?string $signature): Request
    {
        $request = Request::create('/webhook/stripe', 'POST', [], [], [], [], $payload);
        if ($signature !== null) {
            $request->headers->set('stripe-signature', $signature);
        }

        return $request;
    }

    private function signature(string $payload, string $secret = self::SECRET): string
    {
        $t = time();

        return sprintf('t=%d,v1=%s', $t, hash_hmac('sha256', $t . '.' . $payload, $secret));
    }

    public function testSignatureInvalideEstRefuseeEtRienNestTraite(): void
    {
        $traitement = $this->createMock(TraitementEvenementStripe::class);
        $traitement->expects($this->never())->method('traiter');
        $payload = '{"id":"evt_1","object":"event","type":"checkout.session.completed","data":{"object":{}}}';

        $reponse = (new StripeWebhookController($traitement, new \Psr\Log\NullLogger()))->handleWebhook(
            $this->requete($payload, $this->signature($payload, 'whsec_autre_secret'))
        );

        $this->assertSame(400, $reponse->getStatusCode());
    }

    /** Cas rencontré en local : le secret du .env n'est pas celui de « stripe listen ». Le refus doit laisser une trace claire dans les logs. */
    public function testUnSecretQuiNeCorrespondPasEstJournaliseAvecUneExplication(): void
    {
        $traitement = $this->createMock(TraitementEvenementStripe::class);
        $traitement->expects($this->never())->method('traiter');
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('STRIPE_WEBHOOK_SECRET'),
            $this->callback(static fn (array $contexte): bool => $contexte['en_tete_present'] === true && $contexte['secret_configure'] === true)
        );
        $payload = '{"id":"evt_1","object":"event","type":"checkout.session.completed","data":{"object":{}}}';

        $reponse = (new StripeWebhookController($traitement, $logger))->handleWebhook(
            $this->requete($payload, $this->signature($payload, 'whsec_celui_de_stripe_listen'))
        );

        $this->assertSame(400, $reponse->getStatusCode());
    }

    public function testSansEnteteDeSignatureEstRefuse(): void
    {
        $traitement = $this->createMock(TraitementEvenementStripe::class);
        $traitement->expects($this->never())->method('traiter');

        $reponse = (new StripeWebhookController($traitement, new \Psr\Log\NullLogger()))->handleWebhook($this->requete('{}', null));

        $this->assertSame(400, $reponse->getStatusCode());
    }

    public function testEvenementSigneEstTraiteEtRepond200AvecLeStatut(): void
    {
        $traitement = $this->createMock(TraitementEvenementStripe::class);
        $traitement->expects($this->once())->method('traiter')->willReturn('deja_traitee');
        $payload = '{"id":"evt_1","object":"event","type":"checkout.session.completed","data":{"object":{"id":"cs_1"}}}';

        $reponse = (new StripeWebhookController($traitement, new \Psr\Log\NullLogger()))->handleWebhook(
            $this->requete($payload, $this->signature($payload))
        );

        $this->assertSame(200, $reponse->getStatusCode());
        $this->assertSame('{"status":"deja_traitee"}', $reponse->getContent());
    }
}
