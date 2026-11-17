<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangerEmailType;
use App\Form\ChangerMotDePasseType;
use App\Service\VerificationEmail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Sécurité du compte : changement de mot de passe et d'adresse e-mail (mot de passe actuel demandé, notification envoyée). */
#[IsGranted('ROLE_USER')]
class CompteSecuriteController extends AbstractController
{
    #[Route('/compte/mot-de-passe', name: 'app_compte_mot_de_passe', methods: ['GET', 'POST'])]
    public function motDePasse(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, VerificationEmail $notifications): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangerMotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('nouveauMotDePasse')->getData()));
            $em->flush();
            $notifications->notifier((string) $user->getEmail(), 'Votre mot de passe a été modifié', 'Mot de passe modifié', 'Le mot de passe de votre compte vient d\'être modifié. Si c\'est bien vous, aucune action n\'est nécessaire.');
            $this->addFlash('success', 'Votre mot de passe a été modifié.');

            return $this->redirectToRoute('app_compte_mot_de_passe');
        }

        return $this->render('compte/mot_de_passe.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }

    #[Route('/compte/email', name: 'app_compte_email', methods: ['GET', 'POST'])]
    public function email(Request $request, EntityManagerInterface $em, VerificationEmail $verification): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangerEmailType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $nouvelle = mb_strtolower(trim((string) $form->get('nouvelEmail')->getData()));
            if ($nouvelle === mb_strtolower((string) $user->getEmail())) {
                $form->get('nouvelEmail')->addError(new \Symfony\Component\Form\FormError('C\'est déjà votre adresse actuelle.'));
            } elseif ($em->getRepository(User::class)->findOneBy(['email' => $nouvelle]) !== null) {
                // Même message de succès que ci-dessous : on ne révèle pas qu'une adresse est déjà utilisée
                $this->addFlash('success', 'Un e-mail de confirmation vient d\'être envoyé à la nouvelle adresse. Votre adresse actuelle reste valable tant qu\'elle n\'est pas confirmée.');

                return $this->redirectToRoute('app_compte_email');
            } else {
                $user->setEmailEnAttente($nouvelle);
                $em->flush();
                $verification->envoyerChangement($user, $nouvelle);
                $this->addFlash('success', 'Un e-mail de confirmation vient d\'être envoyé à la nouvelle adresse. Votre adresse actuelle reste valable tant qu\'elle n\'est pas confirmée.');

                return $this->redirectToRoute('app_compte_email');
            }
        }

        return $this->render('compte/email.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }
}
