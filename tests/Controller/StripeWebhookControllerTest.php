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

        $reponse = (new StripeWebhookController($traitement))->handleWebhook(
            $this->requete($payload, $this->signature($payload, 'whsec_autre_secret'))
        );

        $this->assertSame(400, $reponse->getStatusCode());
    }

    public function testSansEnteteDeSignatureEstRefuse(): void
    {
        $traitement = $this->createMock(TraitementEvenementStripe::class);
        $traitement->expects($this->never())->method('traiter');

        $reponse = (new StripeWebhookController($traitement))->handleWebhook($this->requete('{}', null));

        $this->assertSame(400, $reponse->getStatusCode());
    }

    public function testEvenementSigneEstTraiteEtRepond200AvecLeStatut(): void
    {
        $traitement = $this->createMock(TraitementEvenementStripe::class);
        $traitement->expects($this->once())->method('traiter')->willReturn('deja_traitee');
        $payload = '{"id":"evt_1","object":"event","type":"checkout.session.completed","data":{"object":{"id":"cs_1"}}}';

        $reponse = (new StripeWebhookController($traitement))->handleWebhook(
            $this->requete($payload, $this->signature($payload))
        );

        $this->assertSame(200, $reponse->getStatusCode());
        $this->assertSame('{"status":"deja_traitee"}', $reponse->getContent());
    }
}
