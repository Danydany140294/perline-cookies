<?php

namespace App\EventListener;

use App\Entity\Box;
use App\Entity\Commande;
use App\Entity\JournalAdmin;
use App\Entity\Produit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Journalise automatiquement les actions sensibles faites DANS l'administration (requête /admin par un administrateur ou un
 * gestionnaire connecté) : prix, stock, taux de TVA, activation / désactivation, rôles, suppressions.
 * Les mouvements de stock causés par les commandes des clients ne sont pas des actions d'administration : ils ne sont pas journalisés ici.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class JournalActionsAdmin
{
    /** @var array<class-string, array<string, string>> champ => libellé */
    public const SUIVI = [
        Produit::class => ['prix' => 'Prix', 'stock' => 'Stock', 'isActive' => 'Actif', 'tauxTva' => 'Taux de TVA'],
        Box::class => ['prix' => 'Prix', 'prix6' => 'Prix 6', 'prix12' => 'Prix 12', 'prix24' => 'Prix 24', 'isActive' => 'Actif', 'tauxTva' => 'Taux de TVA'],
        User::class => ['roles' => 'Rôles'],
    ];

    public function __construct(private Security $security, private RequestStack $requetes)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $auteur = $this->auteur();
        if ($auteur === null) {
            return;
        }

        /** @var EntityManagerInterface $em */
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(JournalAdmin::class);
        $lignes = [];

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            $suivi = self::SUIVI[$entite::class] ?? null;
            if ($suivi === null) {
                continue;
            }
            $avant = $apres = [];
            foreach ($uow->getEntityChangeSet($entite) as $champ => [$ancien, $nouveau]) {
                if (!isset($suivi[$champ]) || $this->normaliser($ancien) === $this->normaliser($nouveau)) {
                    continue;
                }
                $avant[$suivi[$champ]] = $this->normaliser($ancien);
                $apres[$suivi[$champ]] = $this->normaliser($nouveau);
            }
            if ($avant === []) {
                continue;
            }
            $lignes[] = new JournalAdmin($auteur, $this->action($avant, $apres), $this->nomEntite($entite), $entite->getId(), $this->libelle($entite), $avant, $apres);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            if ($entite instanceof Produit || $entite instanceof Box || $entite instanceof User || $entite instanceof Commande) {
                $lignes[] = new JournalAdmin($auteur, JournalAdmin::ACTION_SUPPRESSION, $this->nomEntite($entite), $entite->getId(), $this->libelle($entite));
            }
        }

        foreach ($lignes as $ligne) {
            $em->persist($ligne);
            $uow->computeChangeSet($meta, $ligne);
        }
    }

    /** E-mail du back-office connecté si on est dans une requête d'administration, sinon null. */
    private function auteur(): ?string
    {
        $requete = $this->requetes->getMainRequest();
        $utilisateur = $this->security->getUser();
        if ($requete === null || !str_starts_with($requete->getPathInfo(), '/admin') || !$utilisateur instanceof User) {
            return null;
        }

        return $this->security->isGranted('ROLE_GESTIONNAIRE') ? $utilisateur->getEmail() : null;
    }

    /** @param array<string, mixed> $avant @param array<string, mixed> $apres */
    private function action(array $avant, array $apres): string
    {
        if (isset($apres['Rôles'])) {
            return JournalAdmin::ACTION_ROLES;
        }
        if (count($apres) === 1 && isset($apres['Actif'])) {
            return $apres['Actif'] === true ? JournalAdmin::ACTION_REACTIVATION : JournalAdmin::ACTION_DESACTIVATION;
        }

        return JournalAdmin::ACTION_MODIFICATION;
    }

    private function normaliser(mixed $valeur): mixed
    {
        if (is_numeric($valeur) && !is_int($valeur)) {
            return (float) $valeur;
        }

        return $valeur;
    }

    private function nomEntite(object $entite): string
    {
        return (new \ReflectionClass($entite))->getShortName();
    }

    private function libelle(object $entite): string
    {
        return match (true) {
            $entite instanceof Produit => 'Cookie « ' . $entite->getName() . ' »',
            $entite instanceof Box => 'Box « ' . $entite->getNom() . ' »',
            $entite instanceof User => 'Utilisateur ' . $entite->getEmail(),
            $entite instanceof Commande => 'Commande ' . $entite->getNumeroCommande(),
            default => $this->nomEntite($entite),
        };
    }
}
