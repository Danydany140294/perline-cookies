<?php

namespace App\Service;

use App\Entity\JournalAdmin;
use Doctrine\ORM\EntityManagerInterface;

/** Écriture explicite dans le journal (contrôleurs, commandes). Les modifications faites dans l'administration sont journalisées automatiquement. */
class JournalAdminService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    public function journaliser(string $auteur, string $action, string $entite, ?int $entiteId, string $libelle, ?array $avant = null, ?array $apres = null): JournalAdmin
    {
        $ligne = new JournalAdmin($auteur, $action, $entite, $entiteId, $libelle, $avant, $apres);
        $this->em->persist($ligne);
        $this->em->flush();

        return $ligne;
    }
}
