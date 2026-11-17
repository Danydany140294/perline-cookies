<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationForm;
use App\Service\VerificationEmail;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        VerificationEmail $verification,
        LoggerInterface $logger
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationForm::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Hash le mot de passe
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            // Rôle par défaut
            $user->setRoles(['ROLE_USER']);
            // L'adresse e-mail doit être confirmée (lien envoyé par e-mail) avant la première connexion
            $user->setEmailVerifie(false);

            // Avatar optionnel
            $avatarFile = $form->get('avatarFile')->getData();
            if ($avatarFile) {
                $extension = strtolower(pathinfo($avatarFile->getClientOriginalName(), PATHINFO_EXTENSION));
                $nouveauNom = uniqid('avatar_') . '.' . $extension;
                $avatarFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/asset/images/home/',
                    $nouveauNom
                );
                $user->setAvatar($nouveauNom);
            }
            // Si pas de fichier → avatar reste null (pas de photo)

            $entityManager->persist($user);
            $entityManager->flush();

            try {
                $verification->envoyerInscription($user);
                $this->addFlash('success', 'Votre compte a été créé. Un e-mail de confirmation vient de vous être envoyé : cliquez sur le lien qu\'il contient pour activer votre compte.');
            } catch (\Throwable $e) {
                $logger->error('Inscription : échec de l\'envoi de l\'e-mail de confirmation.', ['erreur' => $e->getMessage()]);
                $this->addFlash('error', 'Votre compte est créé mais l\'e-mail de confirmation n\'a pas pu être envoyé. Utilisez « Renvoyer le lien de confirmation » sur la page de connexion.');
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}