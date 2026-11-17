<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Adresse;
use App\Form\UserForm;
use App\Form\AdresseType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\ValidationProfil;

#[Route('/user')]
final class UserController extends AbstractController
{
    // ========================================
    // ROUTES FIXES (avant les routes /{id})
    // ========================================

    /**
     * Liste tous les utilisateurs (admin uniquement)
     */
    #[Route('', name: 'app_user_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    /**
     * Créer un nouvel utilisateur (admin uniquement)
     */
    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        $form = $this->createForm(UserForm::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($user);
            $entityManager->flush();

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * Tableau de bord de l'utilisateur connecté (les adresses se gèrent sur /compte/adresses)
     * ⚠️ Doit être AVANT /{id} pour éviter les conflits
     */
    #[Route('/mon-compte', name: 'app_user_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dashboard(Request $request, ?\Symfony\Component\Form\FormView $adresse_form = null, bool $adresse_edition = false, ?\App\Entity\Adresse $adresse_a_modifier = null): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Onglet « Mes Adresses » : la MÊME vue que /compte/adresses (compte/_adresses_vue.html.twig) ; le formulaire est traité par AdresseController
        $formulaireVide = $adresse_form === null;
        $adresse_form ??= $this->createForm(\App\Form\AdresseType::class, (new \App\Entity\Adresse())->setUser($user), [
            'action' => $this->generateUrl('app_adresses_index', ['depuis' => 'compte']),
        ])->createView();

        return $this->render('user/mon_compte.html.twig', [
            'user' => $user,
            'addressForm' => $adresse_form,
            'editMode' => $adresse_edition,
            'adresseToEdit' => $adresse_a_modifier,
            'onglet_force' => $formulaireVide ? null : 'adresses',
        ]);
    }

    /**
     * Mise à jour du profil en AJAX (inline edit)
     * ⚠️ Doit être AVANT /{id} pour éviter les conflits
     */
    #[Route('/mon-compte/update-profil', name: 'app_user_update_profil', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfil(Request $request, EntityManagerInterface $entityManager, ValidationProfil $validation): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'error' => 'Connexion requise.'], 401);
        }

        if (!$this->isCsrfTokenValid('profil', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['success' => false, 'error' => 'Action refusée (jeton de sécurité invalide). Rechargez la page.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        // Validation côté serveur : rien n'est enregistré si un champ est invalide
        $resultat = $validation->valider($data);
        if ($resultat['erreurs'] !== []) {
            return new JsonResponse(['success' => false, 'errors' => $resultat['erreurs'], 'error' => reset($resultat['erreurs'])], 422);
        }

        $v = $resultat['valeurs'];
        $user->setNom($v['nom']);
        $user->setPrenom($v['prenom']);
        $user->setTelephone($v['telephone']);
        $user->setDateNaissance($v['dateNaissance'] !== null ? \DateTime::createFromImmutable($v['dateNaissance']) : null);
        $entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/mon-compte/update-avatar', name: 'app_user_update_avatar', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateAvatar(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'error' => 'Connexion requise.'], 401);
        }

        if (!$this->isCsrfTokenValid('profil', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['success' => false, 'error' => 'Action refusée (jeton de sécurité invalide). Rechargez la page.'], 403);
        }

        $file = $request->files->get('avatar');

        if (!$file) {
            return new JsonResponse(['success' => false, 'error' => 'Aucun fichier reçu'], 400);
        }

        // Vérifier le type via l'extension (sans dépendance à l'extension fileinfo)
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions)) {
            return new JsonResponse(['success' => false, 'error' => 'Format non autorisé'], 400);
        }

        // Supprimer l'ancien avatar si ce n'est pas le défaut
        $ancienAvatar = $user->getAvatar();
        if ($ancienAvatar && $ancienAvatar !== 'avatar.jpg') {
            $ancienChemin = $this->getParameter('kernel.project_dir') . '/public/asset/images/home/' . $ancienAvatar;
            if (file_exists($ancienChemin)) {
                unlink($ancienChemin);
            }
        }

        // Générer un nom unique et déplacer le fichier
        $nouveauNom = uniqid('avatar_') . '.' . $extension;
        $destination = $this->getParameter('kernel.project_dir') . '/public/asset/images/home/';
        $file->move($destination, $nouveauNom);

        // Sauvegarder en base
        $user->setAvatar($nouveauNom);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'avatarUrl' => '/asset/images/home/' . $nouveauNom
        ]);
    }

    // ========================================
    // ROUTES DYNAMIQUES /{id} (après les routes fixes)
    // ========================================

    /**
     * Afficher un utilisateur spécifique (admin uniquement)
     */
    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(User $user): Response
    {
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * Éditer un utilisateur (admin uniquement)
     */
    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserForm::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * Supprimer un utilisateur (admin uniquement)
     */
    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($user);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
}