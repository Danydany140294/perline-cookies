<?php

namespace App\Controller;

use App\Entity\Facture;
use App\Service\Facturation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Téléchargement des factures et avoirs : réservé au propriétaire de la commande (les PDF ne sont jamais dans le dossier public). */
#[IsGranted('ROLE_USER')]
class FactureController extends AbstractController
{
    #[Route('/compte/factures/{id}/telecharger', name: 'app_facture_telecharger', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function telecharger(Facture $facture, Facturation $facturation): BinaryFileResponse
    {
        // 404 (et non 403) pour ne pas révéler l'existence de la facture d'un autre client
        if ($facture->getCommande()->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $chemin = $facturation->cheminPdf($facture);
        if ($chemin === null) {
            throw $this->createNotFoundException('Document introuvable.');
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', 'application/pdf');
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $facture->getNumero() . '.pdf');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');

        return $reponse;
    }
}
