<?php

namespace App\Tests\Service;

use App\Entity\Adresse;
use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\User;
use App\Enum\CommandeStatut;
use App\Service\AnonymisationCompte;
use App\Service\ExportDonneesClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AnonymisationCompteTest extends TestCase
{
    private function utilisateurAvecCommande(CommandeStatut $statut): User
    {
        $user = (new User())->setEmail('jean@exemple.test')->setNom('Dupont')->setPrenom('Jean')->setTelephone('0612345678')->setPassword('hash')->setPointsFidelite(120);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);
        $commande = (new Commande())->setStatut($statut);
        $user->addCommande($commande);
        $user->addAdresse((new Adresse())->setNom('Maison')->setRue('1 rue X')->setCodePostal('34000')->setVille('Montpellier')->setPays('France'));

        return $user;
    }

    private function service(?EntityManagerInterface &$em = null): AnonymisationCompte
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f) => $f($em));
        $requete = $this->createMock(Query::class);
        $requete->method('setParameter')->willReturnSelf();
        $em->method('createQuery')->willReturn($requete);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hash-aleatoire');

        return new AnonymisationCompte($em, $hasher, sys_get_temp_dir());
    }

    /** @dataProvider enCours */
    public function testRefuseTantqueUneCommandeEstEnCours(CommandeStatut $statut): void
    {
        $user = $this->utilisateurAvecCommande($statut);
        $service = $this->service();

        $this->assertStringContainsString('commande(s) en cours', (string) $service->refus($user));
        $this->expectException(\DomainException::class);
        $service->anonymiser($user);
    }

    /** @return iterable<string, array{CommandeStatut}> */
    public static function enCours(): iterable
    {
        foreach ([CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE, CommandeStatut::EN_PREPARATION, CommandeStatut::EXPEDIEE, CommandeStatut::RETOURNEE, CommandeStatut::A_VERIFIER] as $s) {
            yield $s->value => [$s];
        }
    }

    /** @dataProvider terminees */
    public function testAnonymiseQuandLesCommandesSontTerminees(CommandeStatut $statut): void
    {
        $user = $this->utilisateurAvecCommande($statut);
        $service = $this->service($em);
        $em->expects($this->once())->method('remove'); // l'adresse (les commandes ne sont JAMAIS supprimées)

        $this->assertNull($service->refus($user));
        $service->anonymiser($user, new \DateTimeImmutable('2026-11-17 10:00'));

        $this->assertSame('anonyme-42@anonymise.invalid', $user->getEmail());
        $this->assertSame('Anonyme', $user->getNom());
        $this->assertSame('Anonyme', $user->getPrenom());
        $this->assertNull($user->getTelephone());
        $this->assertNull($user->getDateNaissance());
        $this->assertNull($user->getAvatar());
        $this->assertSame(0, $user->getPointsFidelite());
        $this->assertSame('hash-aleatoire', $user->getPassword());
        $this->assertTrue($user->isAnonymise());
        $this->assertFalse($user->isEmailVerifie());
        $this->assertCount(1, $user->getCommandes(), 'Les commandes restent rattachées (conservation 10 ans)');
    }

    /** @return iterable<string, array{CommandeStatut}> */
    public static function terminees(): iterable
    {
        foreach ([CommandeStatut::LIVREE, CommandeStatut::ANNULEE, CommandeStatut::REMBOURSEE, CommandeStatut::EN_ATTENTE] as $s) {
            yield $s->value => [$s];
        }
    }

    public function testExportContientInformationsAdressesEtCommandes(): void
    {
        $user = $this->utilisateurAvecCommande(CommandeStatut::LIVREE);
        $export = (new ExportDonneesClient())->exporter($user, new \DateTimeImmutable('2026-11-17'));

        $this->assertSame('jean@exemple.test', $export['informations']['email']);
        $this->assertSame('Dupont', $export['informations']['nom']);
        $this->assertSame('Montpellier', $export['adresses'][0]['ville']);
        $this->assertCount(1, $export['commandes']);
        $this->assertSame('Livrée', $export['commandes'][0]['statut']);
        $this->assertArrayHasKey('documents', $export['commandes'][0]);
        $this->assertJson(json_encode($export, JSON_THROW_ON_ERROR));
    }
}
