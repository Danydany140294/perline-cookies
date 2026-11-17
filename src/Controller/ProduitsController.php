<?php

namespace App\Controller;

use App\Repository\ProduitRepository;
use App\Repository\BoxRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ProduitsController extends AbstractController
{
    #[Route('/produits', name: 'app_produits')]
    public function index(
        ProduitRepository $produitRepository,
        BoxRepository $boxRepository
    ): Response
    {
        // Tous les cookies ACTIFS : ceux en rupture sont affichés avec le badge « Indisponible » et l'achat désactivé
        $produits = $produitRepository->findAffichables(['name' => 'ASC']);

        // Récupérer la box personnalisable pour la modal
        $boxPersonnalisable = $boxRepository->findOneBy(['type' => 'personnalisable', 'isActive' => true]);

        return $this->render('Page/produits.html.twig', [
            'produits' => $produits,
            'box_personnalisable' => $boxPersonnalisable,
        ]);
    }
}