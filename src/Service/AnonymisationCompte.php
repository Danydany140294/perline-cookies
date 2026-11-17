<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Enum\CommandeStatut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suppression d'un compte par ANONYMISATION (RGPD, droit à l'effacement).
 *
 * - Les commandes, factures et avoirs sont CONSERVÉS 10 ans (obligation comptable) : leur copie figée (destinataire, adresse)
 *   reste, mais le compte auquel ils se rattachent n'est plus identifiable (nom, e-mail, téléphone, date de naissance, avatar,
 *   adresses, favoris, avis, points et demandes de réinitialisation sont supprimés ; l'accès est définitivement fermé).
 * - Refusée tant qu'une commande est en cours (payée, en préparation, expédiée, retournée, à vérifier).
 */
class AnonymisationCompte
{
    private const STATUTS_EN_COURS = [
        CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE, CommandeStatut::EN_PREPARATION,
        CommandeStatut::EXPEDIEE, CommandeStatut::RETOURNEE, CommandeStatut::A_VERIFIER,
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.project_dir%')] private string $racine,
    ) {
    }

    /** Message de refus, ou null si la suppression est possible. */
    public function refus(User $user): ?string
    {
        $enCours = array_filter(
            $user->getCommandes()->toArray(),
            static fn (Commande $c): bool => in_array($c->getStatut(), self::STATUTS_EN_COURS, true)
        );
        if ($enCours !== []) {
            return sprintf(
                'Vous avez %d commande(s) en cours de traitement ou de livraison : votre compte pourra être supprimé une fois qu\'elles seront terminées (livrées, annulées ou remboursées).',
                count($enCours)
            );
        }

        return null;
    }

    public function anonymiser(User $user, ?\DateTimeImmutable $maintenant = null): void
    {
        if (($motif = $this->refus($user)) !== null) {
            throw new \DomainException($motif);
        }
        $avatar = $user->getAvatar();

        $this->em->wrapInTransaction(function () use ($user, $maintenant): void {
            foreach ($user->getAdresses()->toArray() as $adresse) {
                $this->em->remove($adresse); // les commandes gardent leur copie figée (lien mis à NULL)
            }
            foreach ($user->getAvis()->toArray() as $avis) {
                $this->em->remove($avis);
            }
            $user->getFavoris()->clear();
            $this->em->createQuery('DELETE FROM ' . ResetPasswordRequest::class . ' r WHERE r.user = :u')->setParameter('u', $user)->execute();

            $user->setEmail(sprintf('anonyme-%d@anonymise.invalid', $user->getId()));
            $user->setNom('Anonyme');
            $user->setPrenom('Anonyme');
            $user->setTelephone(null);
            $user->setDateNaissance(null);
            $user->setAvatar(null);
            $user->setEmailEnAttente(null);
            $user->setPointsFidelite(0);
            $user->setStripeCustomerId(null);
            $user->setRoles([]);
            $user->setEmailVerifie(false);
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32)))); // accès définitivement fermé
            $user->setAnonymiseLe($maintenant ?? new \DateTimeImmutable());
            $this->em->flush();
        });

        if ($avatar !== null && $avatar !== 'avatar.jpg') {
            (new Filesystem())->remove($this->racine . '/public/asset/images/home/' . basename($avatar));
        }
    }
}
