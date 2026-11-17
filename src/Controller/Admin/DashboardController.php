<?php

namespace App\Controller\Admin;

use App\Entity\Box;
use App\Entity\Commande;
use App\Entity\JournalAdmin;
use App\Entity\LigneCommande;
use App\Entity\LignePanier;
use App\Entity\Panier;
use App\Entity\Produit;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Form\ParametresBoutiqueType;
use App\Service\IdentiteBoutique;
use App\Service\JournalAdminService;
use App\Service\ParametresBoutique;
use App\Service\StatistiquesAdmin;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_GESTIONNAIRE')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private UserRepository $userRepository,
        private StatistiquesAdmin $statistiques,
        private IdentiteBoutique $boutique,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard')]
    public function index(): Response
    {
        $contexte = ['stats' => $this->statistiques->calculer(), 'seuil_global' => $this->boutique->seuilStockGlobal()];

        // Statistiques d'utilisateurs : secondaires, réservées à l'administrateur
        if ($this->isGranted('ROLE_ADMIN')) {
            $total = $this->userRepository->count([]);
            $admins = count($this->userRepository->findByRole('ROLE_ADMIN'));
            $contexte += [
                'userCount' => $total,
                'adminsCount' => $admins,
                'usersCount' => $total - $admins,
                'derniersUtilisateurs' => $this->userRepository->findBy([], ['id' => 'DESC'], 5),
            ];
        }

        return $this->render('admin/dashboard.html.twig', $contexte);
    }

    /** Page des exports CSV (dans le contrôleur du tableau de bord : le gabarit EasyAdmin a besoin de son contexte). Administrateur seulement. */
    #[Route('/admin/export', name: 'admin_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(): Response
    {
        return $this->render('admin/export.html.twig');
    }

    /**
     * « Paramètres de la boutique » : identité, contact, retrait, livraison, stock, fonctions optionnelles, mode démo. Administrateur
     * seulement. Les valeurs de config/boutique.yaml sont les valeurs par défaut ; aucun secret ici ; chaque modification est journalisée.
     */
    #[Route('/admin/parametres', name: 'admin_parametres', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function parametres(Request $requete, ParametresBoutique $parametres, JournalAdminService $journal): Response
    {
        $donnees = [];
        foreach ($parametres->valeursEffectives() as $cle => $valeur) {
            $donnees[ParametresBoutiqueType::nomChamp($cle)] = $valeur;
        }
        $formulaire = $this->createForm(ParametresBoutiqueType::class, $donnees);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $saisie = [];
            foreach ((array) $formulaire->getData() as $nom => $valeur) {
                $saisie[str_replace('__', '.', (string) $nom)] = $valeur;
            }
            if (empty($saisie['livraison.modes.livraison']) && empty($saisie['livraison.modes.retrait'])) {
                $formulaire->addError(new FormError('Au moins un mode de réception doit rester actif (livraison à domicile ou retrait sur place).'));
            } else {
                $changements = $parametres->enregistrer($saisie, (string) $this->getUser()?->getUserIdentifier(), $journal);
                $this->addFlash('success', $changements === [] ? 'Aucun changement.' : sprintf('Paramètres enregistrés (%d modification(s), tracées dans le journal).', count($changements)));

                return $this->redirectToRoute('admin_parametres');
            }
        }

        $groupes = [];
        foreach (ParametresBoutique::CHAMPS as $cle => $champ) {
            $groupes[$champ['groupe']][] = ParametresBoutiqueType::nomChamp($cle);
        }

        return $this->render('admin/parametres.html.twig', ['formulaire' => $formulaire, 'groupes' => $groupes]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Dashboard Admin - ' . $this->boutique->get('nom_marque'));
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linktoDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Gestion Utilisateurs')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToCrud('Utilisateurs', 'fa fa-users', User::class)
            ->setController(UserCrudController::class)->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToCrud('Journal des actions', 'fa fa-clipboard-list', JournalAdmin::class)
            ->setController(JournalAdminCrudController::class)->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Catalogue');
        yield MenuItem::linkToCrud('Cookies', 'fa fa-cookie', Produit::class)
            ->setController(ProduitCrudController::class);
        yield MenuItem::linkToCrud('Boxes', 'fa fa-box', Box::class)
            ->setController(BoxCrudController::class);

        yield MenuItem::section('Ventes & Commandes');
        yield MenuItem::linkToCrud('Commandes', 'fa fa-shopping-cart', Commande::class)
            ->setController(CommandeCrudController::class);
        yield MenuItem::linkToCrud('Lignes de Commande', 'fa fa-list', LigneCommande::class);

        yield MenuItem::linkToRoute('Paramètres de la boutique', 'fa fa-gear', 'admin_parametres')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Exports CSV', 'fa fa-file-csv', 'admin_export')->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Paniers')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToCrud('Paniers Actifs', 'fa fa-shopping-basket', Panier::class)->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToCrud('Lignes de Panier', 'fa fa-align-justify', LignePanier::class)->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Navigation');
        yield MenuItem::linkToRoute('Retour au site', 'fa fa-arrow-left', 'app_home');
    }
}
