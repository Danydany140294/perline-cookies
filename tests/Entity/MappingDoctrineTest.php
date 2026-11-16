<?php

namespace App\Tests\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Le mapping Doctrine de toutes les entités doit rester cohérent (côté propriétaire / côté inverse, avis et favoris compris). */
class MappingDoctrineTest extends KernelTestCase
{
    public function testLeMappingDeToutesLesEntitesEstValide(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->assertSame([], (new SchemaValidator($em))->validateMapping());
    }
}
