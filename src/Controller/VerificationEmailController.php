<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\VerificationEmail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** Confirmation d'adresse e-mail (inscription, changement d'adresse) et renvoi du lien. */
class VerificationEmailController extends AbstractController
{
    #[Route('/verification-email', name: 'app_verifier_email', methods: ['GET'])]
    public function verifier(Request $request, VerificationEmail $verification, EntityManagerInterface $em, Security $security): Response
    {
        $expires = $request->query->get('expires');
        if (!$verification->verifier($request->getUri(), ctype_digit((string) $expires) ? (int) $expires : null)) {
            $this->addFlash('error', 'Ce lien de confirmation est invalide ou a expiré. Demandez-en un nouveau.');

            return $this->redirectToRoute('app_renvoyer_verification');
        }

        $user = $em->find(User::class, (int) $request->query->get('id'));
        $type = (string) $request->query->get('type');
        $email = mb_strtolower((string) $request->query->get('email'));
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        if ($type === VerificationEmail::TYPE_INSCRIPTION && mb_strtolower((string) $user->getEmail()) === $email) {
            $user->setEmailVerifie(true);
            $em->flush();
            $this->addFlash('success', 'Votre adresse e-mail est confirmée : vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        if ($type === VerificationEmail::TYPE_CHANGEMENT && $user->getEmailEnAttente() !== null && mb_strtolower($user->getEmailEnAttente()) === $email) {
            if ($em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
                $user->setEmailEnAttente(null);
                $em->flush();
                $this->addFlash('error', 'Cette adresse est déjà utilisée par un autre compte.');

                return $this->redirectToRoute('app_login');
            }
            $ancienne = (string) $user->getEmail();
            $user->setEmail($email);
            $user->setEmailEnAttente(null);
            $em->flush();
            $verification->notifier($ancienne, 'Votre adresse e-mail a été modifiée', 'Adresse e-mail modifiée', sprintf('L\'adresse e-mail de votre compte est désormais %s. Vous devez utiliser cette nouvelle adresse pour vous connecter.', $email));
            // Déconnexion explicite (la route /logout exige maintenant un jeton CSRF) ; le message est ajouté APRÈS : la session est neuve.
            $security->logout(false);
            $this->addFlash('success', 'Votre nouvelle adresse e-mail est confirmée. Reconnectez-vous avec cette adresse.');

            return $this->redirectToRoute('app_login');
        }

        // Lien déjà utilisé ou devenu sans objet
        $this->addFlash('error', 'Ce lien n\'est plus valable.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/verification-email/renvoyer', name: 'app_renvoyer_verification', methods: ['GET', 'POST'])]
    public function renvoyer(Request $request, EntityManagerInterface $em, VerificationEmail $verification, RateLimiterFactory $verificationEmailLimiter): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('renvoyer_verification', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Action refusée (jeton de sécurité invalide).');

                return $this->redirectToRoute('app_renvoyer_verification');
            }
            if (!$verificationEmailLimiter->create($request->getClientIp() ?? 'inconnue')->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop de demandes. Merci de réessayer dans quelques minutes.');

                return $this->redirectToRoute('app_renvoyer_verification');
            }

            $user = $em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($request->request->getString('email')))]);
            if ($user !== null && !$user->isEmailVerifie()) {
                $verification->envoyerInscription($user);
            }
            // Même réponse dans tous les cas : on ne révèle pas si l'adresse a un compte
            $this->addFlash('success', 'Si un compte non confirmé existe pour cette adresse, un nouveau lien vient d\'être envoyé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/renvoyer_verification.html.twig');
    }
}
