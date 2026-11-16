<?php

namespace App\Controller;

use App\Repository\CommandeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Détail d'une commande dans le compte client : réservé au propriétaire (404 pour tout autre : autre compte ou visiteur). */
class CompteCommandeController extends AbstractController
{
    #[Route('/compte/commandes/{id}', name: 'app_compte_commande', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, CommandeRepository $commandes, #[Autowire(param: 'retrait')] array $retrait): Response
    {
        $utilisateur = $this->getUser();
        $commande = $utilisateur !== null ? $commandes->find($id) : null;

        // 404 et non 403 : on ne révèle pas l'existence de la commande d'un autre client
        if ($commande === null || $commande->getUser() !== $utilisateur) {
            throw $this->createNotFoundException();
        }

        return $this->render('compte/commande.html.twig', ['commande' => $commande, 'retrait' => $retrait]);
    }
}
