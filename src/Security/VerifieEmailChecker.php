<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Un compte dont l'adresse e-mail n'est pas confirmée ne peut pas se connecter. */
class VerifieEmailChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isEmailVerifie()) {
            throw new CustomUserMessageAccountStatusException('Votre adresse e-mail n\'est pas encore confirmée : cliquez sur le lien reçu par e-mail (pensez à vérifier vos courriers indésirables).');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
