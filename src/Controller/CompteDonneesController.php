<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\SupprimerCompteType;
use App\Service\AnonymisationCompte;
use App\Service\ExportDonneesClient;
use App\Service\VerificationEmail;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Données personnelles (RGPD) : export et suppression du compte par anonymisation. */
#[IsGranted('ROLE_USER')]
class CompteDonneesController extends AbstractController
{
    #[Route('/compte/mes-donnees', name: 'app_compte_donnees', methods: ['GET', 'POST'])]
    public function donnees(Request $request, AnonymisationCompte $anonymisation, VerificationEmail $notifications, TokenStorageInterface $jetons, LoggerInterface $logger): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $refus = $anonymisation->refus($user);
        $form = $this->createForm(SupprimerCompteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($refus !== null) {
                $this->addFlash('error', $refus);

                return $this->redirectToRoute('app_compte_donnees');
            }

            $adresse = (string) $user->getEmail();
            $anonymisation->anonymiser($user);
            try {
                $notifications->notifier($adresse, 'Votre compte a été supprimé', 'Compte supprimé', 'Votre compte a bien été supprimé : vos données personnelles ont été anonymisées. Seuls vos commandes et justificatifs de paiement sont conservés 10 ans, comme l\'impose la loi comptable.');
            } catch (\Throwable $e) {
                $logger->error('Suppression de compte : notification non envoyée.', ['erreur' => $e->getMessage()]);
            }

            // Déconnexion immédiate
            $jetons->setToken(null);
            $request->getSession()->invalidate();
            $request->getSession()->getFlashBag()->add('success', 'Votre compte a été supprimé et vos données personnelles anonymisées. À bientôt peut-être !');

            return $this->redirectToRoute('app_home');
        }

        return $this->render('compte/donnees.html.twig', ['form' => $form, 'refus' => $refus], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }

    #[Route('/compte/mes-donnees/export', name: 'app_compte_donnees_export', methods: ['GET'])]
    public function export(ExportDonneesClient $export): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $reponse = new JsonResponse($export->exporter($user), 200, [], false);
        $reponse->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reponse->headers->set('Content-Disposition', $reponse->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'mes-donnees.json'));
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }
}
