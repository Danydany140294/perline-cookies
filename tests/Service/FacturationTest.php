<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Entity\Facture;
use App\Enum\CommandeStatut;
use App\EventListener\FactureImmuable;
use App\Repository\FactureRepository;
use App\Service\CalculTva;
use App\Service\Facturation;
use App\Service\IdentiteBoutique;
use App\Service\PdfFacture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;

class FacturationTest extends TestCase
{
    private function facturation(): Facturation
    {
        return new Facturation(
            $this->createMock(EntityManagerInterface::class), $this->createMock(FactureRepository::class), new IdentiteBoutique([]),
            new CalculTva(), $this->createMock(PdfFacture::class), sys_get_temp_dir()
        );
    }

    private function facture(): Facture
    {
        return new Facture(Facture::TYPE_FACTURE, 'F-2026-000001', 2026, 1, new Commande(), null, new \DateTimeImmutable(), 9.48, 0.52, 10.0, [], [], [], [], true);
    }

    public function testSeulesLesCommandesPayeesAvecTvaFigeeSontFacturables(): void
    {
        $f = $this->facturation();

        $ancienne = (new Commande())->setStatut(CommandeStatut::PAYEE); // payée avant le chapitre 1.4 : pas de TVA figée
        $this->assertFalse($f->estFacturable($ancienne));

        $payee = (new Commande())->setStatut(CommandeStatut::PAYEE)->figerTva(9.48, 0.52, [['taux' => 5.5, 'ht' => 9.48, 'tva' => 0.52, 'ttc' => 10.0]]);
        $this->assertTrue($f->estFacturable($payee));

        $enAttente = (new Commande())->setStatut(CommandeStatut::EN_ATTENTE)->figerTva(9.48, 0.52, [['taux' => 5.5, 'ht' => 9.48, 'tva' => 0.52, 'ttc' => 10.0]]);
        $this->assertFalse($f->estFacturable($enAttente), 'Pas de facture avant le paiement');
        $this->assertFalse($f->estFacturable((clone $enAttente)->setStatut(CommandeStatut::ANNULEE)));
    }

    public function testUneFactureEmiseNePeutNiEtreModifieeNiSupprimee(): void
    {
        $facture = $this->facture();
        $ecouteur = new FactureImmuable();

        $maj = $this->createMock(PreUpdateEventArgs::class);
        $maj->method('getObject')->willReturn($facture);
        $this->expectException(\LogicException::class);
        $ecouteur->preUpdate($maj);
    }

    public function testSuppressionRefusee(): void
    {
        $sup = new PreRemoveEventArgs($this->facture(), $this->createMock(EntityManagerInterface::class));

        $this->expectException(\LogicException::class);
        (new FactureImmuable())->preRemove($sup);
    }

    public function testLesAutresEntitesNeSontPasConcernees(): void
    {
        $maj = $this->createMock(PreUpdateEventArgs::class);
        $maj->method('getObject')->willReturn(new Commande());

        (new FactureImmuable())->preUpdate($maj);
        $this->addToAssertionCount(1);
    }

    public function testLePdfNePeutEtreAttacheQuUneFois(): void
    {
        $facture = $this->facture();
        $facture->attacherPdf('2026/F-2026-000001.pdf', str_repeat('a', 64));
        $this->assertSame('2026/F-2026-000001.pdf', $facture->getCheminPdf());

        $this->expectException(\LogicException::class);
        $facture->attacherPdf('autre.pdf', str_repeat('b', 64));
    }

    public function testAvoirEtMontantsSignes(): void
    {
        $avoir = new Facture(Facture::TYPE_AVOIR, 'A-2026-000001', 2026, 1, new Commande(), $this->facture(), new \DateTimeImmutable(), -4.74, -0.26, -5.0, [], [], [], [], false);

        $this->assertTrue($avoir->estAvoir());
        $this->assertTrue($avoir->isAvoir());
        $this->assertSame(-5.0, $avoir->getTotalTtc());
        $this->assertSame($this->facture()->getNumero(), $avoir->getFactureOrigine()->getNumero());
    }
}
