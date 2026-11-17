<?php

namespace App\Controller;

use App\Entity\Adresse;
use App\Form\AdresseType;
use App\Service\AdresseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compte/adresses')]
#[IsGranted('ROLE_USER')]
class AdresseController extends AbstractController
{
    public function __construct(
        private AdresseService $adresseService
    ) {}

    #[Route('', name: 'app_adresses_index')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $adresse = new Adresse();
        $adresse->setUser($user);

        $form = $this->createForm(AdresseType::class, $adresse, ['action' => $request->getRequestUri()]); // l'URL peut être réécrite par l'onglet du tableau de bord : on poste toujours ici
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($adresse->isParDefaut()) {
                $this->adresseService->removeDefaultFromOtherAddresses($user);
            }

            if ($user->getAdresses()->count() === 0) {
                $adresse->setParDefaut(true);
            }

            $em->persist($adresse);
            $em->flush();

            $this->addFlash('success', 'Adresse ajoutée avec succès.');
            return $this->retour($request);
        }

        return $this->afficher($request, $form, false, null);
    }

    #[Route('/{id}/modifier', name: 'app_adresses_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Adresse $adresse, Request $request, EntityManagerInterface $em): Response
    {
        if ($adresse->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(AdresseType::class, $adresse, ['action' => $request->getRequestUri()]); // l'URL peut être réécrite par l'onglet du tableau de bord : on poste toujours ici
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($adresse->isParDefaut()) {
                $this->adresseService->removeDefaultFromOtherAddresses($this->getUser());
                $adresse->setParDefaut(true);
            }

            $em->flush();

            $this->addFlash('success', 'Adresse modifiée avec succès.');
            return $this->retour($request);
        }

        return $this->afficher($request, $form, true, $adresse);
    }

    #[Route('/{id}/supprimer', name: 'app_adresses_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Adresse $adresse, Request $request, EntityManagerInterface $em): Response
    {
        if ($adresse->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('adresse_supprimer_' . $adresse->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Action refusée (jeton de sécurité invalide). Merci de réessayer.');

            return $this->retour($request);
        }

        $userAddresses = $this->getUser()->getAdresses();
        if ($adresse->isParDefaut() && count($userAddresses) > 1) {
            $message = 'Vous ne pouvez pas supprimer votre adresse par défaut. Définissez d\'abord une autre adresse comme adresse par défaut.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => $message], 400);
            }
            $this->addFlash('warning', $message);
            return $this->retour($request);
        }

        $wasDefault = $adresse->isParDefaut();

        $em->remove($adresse);
        $em->flush();

        if ($wasDefault) {
            $autreAdresse = $this->getUser()->getAdresses()->first();
            if ($autreAdresse) {
                $autreAdresse->setParDefaut(true);
                $em->flush();
            }
        }

        $message = 'Adresse supprimée avec succès';

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $message]);
        }

        $this->addFlash('success', $message);
        return $this->retour($request);
    }

    #[Route('/{id}/definir-par-defaut', name: 'app_adresses_set_default', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setDefault(Adresse $adresse, Request $request, EntityManagerInterface $em): Response
    {
        if ($adresse->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('adresse_defaut_' . $adresse->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Action refusée (jeton de sécurité invalide). Merci de réessayer.');

            return $this->retour($request);
        }

        $this->adresseService->removeDefaultFromOtherAddresses($this->getUser());
        $adresse->setParDefaut(true);
        $em->flush();

        $message = 'Adresse définie par défaut';

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $message]);
        }

        $this->addFlash('success', $message);
        return $this->retour($request);
    }

    /** Vue demandée depuis l'onglet « Mes Adresses » du tableau de bord (?depuis=compte) : mêmes contrôles, retour dans l'onglet. */
    private function depuisCompte(Request $request): bool
    {
        return $request->query->get('depuis') === 'compte';
    }

    /** Retour après une action : la page des adresses, ou l'onglet « Mes Adresses » du tableau de bord. */
    private function retour(Request $request): Response
    {
        if ($this->depuisCompte($request)) {
            return $this->redirect($this->generateUrl('app_user_dashboard') . '#adresses');
        }

        return $this->redirectToRoute('app_adresses_index');
    }

    /** Affiche la vue partagée : sur sa page, ou dans le tableau de bord (formulaire, erreurs et mode modification compris). */
    private function afficher(Request $request, \Symfony\Component\Form\FormInterface $form, bool $edition, ?Adresse $adresse): Response
    {
        $statut = $form->isSubmitted() && !$form->isValid() ? 422 : 200;
        if ($this->depuisCompte($request)) {
            $reponse = $this->forward(UserController::class . '::dashboard', [
                'adresse_form' => $form->createView(),
                'adresse_edition' => $edition,
                'adresse_a_modifier' => $adresse,
            ]);
            $reponse->setStatusCode($statut);

            return $reponse;
        }

        return $this->render('compte/adresses.html.twig', [
            'addressForm' => $form->createView(),
            'editMode' => $edition,
            'adresseToEdit' => $adresse,
        ], new Response(status: $statut));
    }
}
