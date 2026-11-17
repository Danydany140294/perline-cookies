<?php

namespace App\Controller\Admin;

use App\Entity\JournalAdmin;
use App\Entity\User;
use App\Service\CsvEcriveur;
use App\Service\ExportCsv;
use App\Service\JournalAdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Exports CSV (commandes, ventes) : réservés à l'administrateur, chaque export est inscrit dans le journal. */
#[IsGranted('ROLE_ADMIN')]
class ExportCsvController extends AbstractController
{
    #[Route('/admin/export/telecharger', name: 'admin_export_telecharger', methods: ['POST'])]
    public function telecharger(Request $request, ExportCsv $export, JournalAdminService $journal): Response
    {
        if (!$this->isCsrfTokenValid('export_csv', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        $type = $request->request->getString('type') === 'ventes' ? 'ventes' : 'commandes';
        $debut = $this->date($request->request->getString('debut'));
        $fin = $this->date($request->request->getString('fin'));
        $toutes = $request->request->getBoolean('toutes');
        if ($debut !== null && $fin !== null && $fin < $debut) {
            $this->addFlash('danger', 'La date de fin est antérieure à la date de début.');

            return $this->redirectToRoute('admin_export');
        }

        $lignes = $type === 'ventes' ? $export->ventes($debut, $fin, $toutes) : $export->commandes($debut, $fin, $toutes);
        $nom = sprintf('%s-%s.csv', $type, (new \DateTimeImmutable())->format('Y-m-d'));

        /** @var User $admin */
        $admin = $this->getUser();
        $nombre = 0;
        $reponse = new StreamedResponse(static function () use ($lignes, &$nombre): void {
            echo CsvEcriveur::BOM;
            foreach ($lignes as $ligne) {
                echo $ligne;
                ++$nombre;
            }
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', $reponse->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nom));
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Cache-Control', 'no-store, private');

        // Trace de l'export (qui, quoi, quelle période) : écrite avant l'envoi du fichier
        $journal->journaliser((string) $admin->getEmail(), JournalAdmin::ACTION_EXPORT, 'Export', null, sprintf('Export CSV des %s', $type), null, [
            'Type' => $type, 'Début' => $debut?->format('Y-m-d') ?? '—', 'Fin' => $fin?->format('Y-m-d') ?? '—', 'Commandes non payées incluses' => $toutes,
        ]);

        return $reponse;
    }

    private function date(string $valeur): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return $d !== false && $d->format('Y-m-d') === $valeur ? $d : null;
    }
}
