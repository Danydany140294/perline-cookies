<?php

namespace App\Tests\EventListener;

use App\EventListener\EnTetesSecuriteListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

class EnTetesSecuriteListenerTest extends TestCase
{
    private function reponse(string $env, Request $requete, ?Response $reponse = null, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $reponse ??= new Response('ok');
        $noyau = $this->createMock(HttpKernelInterface::class);
        (new EnTetesSecuriteListener($env))(new ResponseEvent($noyau, $requete, $type, $reponse));

        return $reponse;
    }

    public function testEntetesDeSecuriteSurToutesLesReponses(): void
    {
        $r = $this->reponse('dev', Request::create('http://x.test/'));

        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $r->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $r->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('camera=()', (string) $r->headers->get('Permissions-Policy'));
        $this->assertFalse($r->headers->has('Strict-Transport-Security'), 'Jamais de HSTS en développement ni en HTTP');
    }

    public function testHstsSeulementEnProductionEtEnHttps(): void
    {
        $this->assertTrue($this->reponse('prod', Request::create('https://x.test/'))->headers->has('Strict-Transport-Security'));
        $this->assertFalse($this->reponse('prod', Request::create('http://x.test/'))->headers->has('Strict-Transport-Security'));
        $this->assertFalse($this->reponse('dev', Request::create('https://x.test/'))->headers->has('Strict-Transport-Security'));
    }

    public function testUnEnteteDejaDefiniNEstPasEcrase(): void
    {
        $deja = new Response('x', 200, ['X-Frame-Options' => 'DENY']);

        $this->assertSame('DENY', $this->reponse('dev', Request::create('http://x.test/'), $deja)->headers->get('X-Frame-Options'));
    }

    public function testSousRequeteIgnoree(): void
    {
        $r = $this->reponse('dev', Request::create('http://x.test/'), null, HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($r->headers->has('X-Frame-Options'));
    }
}
