<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Security\VerifieEmailChecker;
use App\Service\IdentiteBoutique;
use App\Service\VerificationEmail;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Twig\Environment;

class VerificationEmailTest extends TestCase
{
    private function service(): VerificationEmail
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p): string => 'https://boutique.test/verification-email?' . http_build_query($p));

        return new VerificationEmail(new UriSigner('secret-de-test'), $urls, $this->createMock(MailerInterface::class), $this->createMock(Environment::class), new IdentiteBoutique([]));
    }

    private function utilisateur(): User
    {
        $u = (new User())->setEmail('a@b.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, 7);

        return $u;
    }

    public function testLienSigneValideTantQueNonExpire(): void
    {
        $s = $this->service();
        $lien = $s->lien($this->utilisateur(), VerificationEmail::TYPE_INSCRIPTION, 'a@b.test', 1_000_000);
        parse_str((string) parse_url($lien, PHP_URL_QUERY), $q);

        $this->assertTrue($s->verifier($lien, (int) $q['expires'], 1_000_100));
        $this->assertSame(1_000_000 + 86400, (int) $q['expires'], 'Validité de 24 h');
    }

    public function testLienExpireRefuse(): void
    {
        $s = $this->service();
        $lien = $s->lien($this->utilisateur(), VerificationEmail::TYPE_INSCRIPTION, 'a@b.test', 1_000_000);

        $this->assertFalse($s->verifier($lien, 1_000_000 + 86400, 1_000_000 + 86401));
    }

    public function testLienFalsifieRefuse(): void
    {
        $s = $this->service();
        $lien = $s->lien($this->utilisateur(), VerificationEmail::TYPE_INSCRIPTION, 'a@b.test', 1_000_000);

        $this->assertFalse($s->verifier(str_replace('a%40b.test', 'pirate%40b.test', $lien), 1_086_400, 1_000_100), 'Adresse modifiée');
        $this->assertFalse($s->verifier(str_replace('id=7', 'id=8', $lien), 1_086_400, 1_000_100), 'Identifiant modifié');
        $this->assertFalse($s->verifier(preg_replace('/expires=\d+/', 'expires=9999999999', $lien), 9_999_999_999, 1_000_100), 'Expiration prolongée');
        $this->assertFalse($s->verifier($lien, null, 1_000_100), 'Sans expiration');
    }

    public function testUnCompteNonConfirmeNePeutPasSeConnecter(): void
    {
        $checker = new VerifieEmailChecker();
        $nonConfirme = (new User())->setEmailVerifie(false);

        $checker->checkPreAuth(new User()); // confirmé par défaut : accepté
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $checker->checkPreAuth($nonConfirme);
    }
}
