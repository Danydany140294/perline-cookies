<?php

namespace App\Tests\Service;

use App\Entity\JournalAdmin;
use App\Entity\User;
use App\EventListener\JournalImmuable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;

class JournalEtRolesTest extends TestCase
{
    public function testGestionnaireSeDonneEtSeRetireSansToucherAuxAutresRoles(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN']);

        $user->setGestionnaire(true);
        $this->assertTrue($user->isGestionnaire());
        $this->assertContains('ROLE_ADMIN', $user->getRoles());

        $user->setGestionnaire(true); // idempotent
        $this->assertSame(1, count(array_keys($user->getRoles(), 'ROLE_GESTIONNAIRE', true)));

        $user->setGestionnaire(false);
        $this->assertFalse($user->isGestionnaire());
        $this->assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testLeRoleAdminNeSeDonneJamaisParLeFormulaireGestionnaire(): void
    {
        $user = new User();
        $user->setGestionnaire(true);

        $this->assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testLeJournalNePeutPasEtreModifieNiSupprime(): void
    {
        $ligne = new JournalAdmin('admin@x.test', JournalAdmin::ACTION_MODIFICATION, 'Produit', 1, 'Cookie', ['Prix' => 4.5], ['Prix' => 5.0]);
        $ecouteur = new JournalImmuable();

        $erreurs = 0;
        try {
            $ecouteur->preRemove(new PreRemoveEventArgs($ligne, $this->createMock(EntityManagerInterface::class)));
        } catch (\LogicException) {
            ++$erreurs;
        }
        $maj = $this->createMock(PreUpdateEventArgs::class);
        $maj->method('getObject')->willReturn($ligne);
        try {
            $ecouteur->preUpdate($maj);
        } catch (\LogicException) {
            ++$erreurs;
        }

        $this->assertSame(2, $erreurs);
    }

    public function testResumeLisible(): void
    {
        $ligne = new JournalAdmin('a@x.test', JournalAdmin::ACTION_MODIFICATION, 'Produit', 1, 'Cookie', ['Prix' => 4.5, 'Actif' => true], ['Prix' => 5.0, 'Actif' => false]);

        $this->assertSame("Prix : 4.5 → 5\nActif : oui → non", $ligne->getResume());
    }
}
