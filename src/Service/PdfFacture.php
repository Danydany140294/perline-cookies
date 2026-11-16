<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use App\Entity\Facture;

/** Génère le PDF d'une facture ou d'un avoir (une seule fois, à l'émission) à partir du template facture/document. */
class PdfFacture
{
    public function __construct(private Environment $twig, private string $dossierTemporaire)
    {
    }

    public function generer(Facture $facture, ?Facture $origine = null): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);   // aucune ressource externe
        $options->set('defaultFont', 'DejaVu Sans'); // gère €, accents
        $options->set('tempDir', $this->dossierTemporaire);
        $options->set('fontCache', $this->dossierTemporaire);

        (new \Symfony\Component\Filesystem\Filesystem())->mkdir($this->dossierTemporaire);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('facture/document.html.twig', ['facture' => $facture, 'origine' => $origine]), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
