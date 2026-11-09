<?php

namespace App\Controller;

use App\Repository\AdresseRepository;
use App\Repository\BoxRepository;
use App\Repository\CommandeRepository;
use App\Repository\PanierRepository;
use App\Repository\LignePanierRepository;
use App\Repository\ProduitRepository;
use App\Service\FraisLivraisonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class PaiementController extends AbstractController
{
    use TargetPathTrait; // ← ajouter cette ligne

    private string $stripeSecretKey;

    public function __construct(private FraisLivraisonService $fraisLivraisonService)
    {
        $this->stripeSecretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        \Stripe\Stripe::setApiKey($this->stripeSecretKey);
    }

    /** Convertit un prix en euros (float) en centimes (int), arrondi correctement. */
    private function enCentimes(float $euros): int
    {
        return (int) round($euros * 100);
    }

    #[Route('/paiement', name: 'app_paiement')]
    public function index(
    PanierRepository $panierRepo,
    LignePanierRepository $lignePanierRepo,
    AdresseRepository $adresseRepo,
    Request $request,
    \App\Service\PanierService $panierService
): Response
    {
       if (!$this->getUser()) {
    $this->saveTargetPath(
        $request->getSession(),
        'main',
        $this->generateUrl('app_user_dashboard') . '#adresses'
    );
    $this->addFlash('warning', 'Veuillez vous connecter pour finaliser votre commande.');
    return $this->redirectToRoute('app_login');
}
    $user = $this->getUser();
    $panierService->migrerSessionVersBDD($user);

        $session = $this->container->get('request_stack')->getSession();
       
        $sousTotalCentimes = 0;
        $panierData = [
            'lignes' => [],
            'total' => 0,
            'nombre_articles' => 0,
            'is_empty' => true
        ];

        if ($user) {
            $panier = $panierRepo->findOneBy(['user' => $user]);

            if ($panier) {
                $lignes = $lignePanierRepo->findBy(['panier' => $panier]);
                $total = 0;
                $nbArticles = 0;
                $sousTotalCentimes = 0;

                foreach ($lignes as $ligne) {
                    $total += $ligne->getSousTotal();
                    $nbArticles += $ligne->getQuantite();
                    $sousTotalCentimes += $this->enCentimes($ligne->getPrixUnitaire()) * $ligne->getQuantite();
                }

                $panierData = [
                    'lignes' => $lignes,
                    'total' => $total,
                    'nombre_articles' => $nbArticles,
                    'is_empty' => empty($lignes)
                ];
            }
        }

        if ($panierData['is_empty']) {
            $this->addFlash('warning', 'Votre panier est vide !');
            return $this->redirectToRoute('app_panier_index');
        }

        // Frais calculés par le service (centimes), convertis en euros pour l'affichage
        $fraisCentimes = $this->fraisLivraisonService->calculer(
            $sousTotalCentimes,
            FraisLivraisonService::MODE_LIVRAISON
        );
        $fraisLivraison = $fraisCentimes / 100;
        $totalFinal = ($sousTotalCentimes + $fraisCentimes) / 100;
        $stripePublicKey = $_ENV['STRIPE_PUBLIC_KEY'] ?? '';

        // ✅ Récupérer les adresses de l'user (par défaut en premier)
        $adresses = $adresseRepo->findBy(['user' => $user], ['parDefaut' => 'DESC']);

        return $this->render('Page/paiement.html.twig', [
            'show_success_modal' => false,
            'panier' => $panierData,
            'frais_livraison' => $fraisLivraison,
            'total_final' => $totalFinal,
            'stripe_public_key' => $stripePublicKey,
            'adresses' => $adresses,
        ]);
    }

    #[Route('/paiement/create-checkout-session', name: 'app_paiement_create_session', methods: ['POST'])]
    public function createCheckoutSession(
        Request $request,
        PanierRepository $panierRepo,
        LignePanierRepository $lignePanierRepo,
        ProduitRepository $produitRepo,
        BoxRepository $boxRepo
    ): JsonResponse
    {
        try {
            $user = $this->getUser();
            $lineItems = [];

            if ($user) {
                $panier = $panierRepo->findOneBy(['user' => $user]);

                if (!$panier) {
                    return new JsonResponse(['error' => 'Panier introuvable'], 404);
                }

                $lignes = $lignePanierRepo->findBy(['panier' => $panier]);

                if (empty($lignes)) {
                    return new JsonResponse(['error' => 'Votre panier est vide'], 400);
                }

                foreach ($lignes as $ligne) {
                    $lineItems[] = [
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => ['name' => $ligne->getNomArticle()],
                            'unit_amount' => $this->enCentimes($ligne->getPrixUnitaire()),
                        ],
                        'quantity' => $ligne->getQuantite(),
                    ];
                }
            } else {
                $session = $this->container->get('request_stack')->getSession();
                $panierSession = $session->get('panier', []);

                if (empty($panierSession['produits']) && empty($panierSession['boxes']) && empty($panierSession['boxes_perso'])) {
                    return new JsonResponse(['error' => 'Votre panier est vide'], 400);
                }

                // Les prix sont relus en base (la session ne garde qu'un identifiant fiable)
                foreach ($panierSession['produits'] ?? [] as $item) {
                    $produit = $produitRepo->find($item['produit']->getId());
                    if (!$produit) {
                        continue;
                    }
                    $lineItems[] = [
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => ['name' => $produit->getName()],
                            'unit_amount' => $this->enCentimes($produit->getPrix()),
                        ],
                        'quantity' => $item['quantite'],
                    ];
                }

                foreach ($panierSession['boxes'] ?? [] as $item) {
                    $box = $boxRepo->find($item['box']->getId());
                    if (!$box) {
                        continue;
                    }
                    $lineItems[] = [
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => ['name' => $box->getNom()],
                            'unit_amount' => $this->enCentimes($box->getPrix()),
                        ],
                        'quantity' => $item['quantite'],
                    ];
                }

                foreach ($panierSession['boxes_perso'] ?? [] as $item) {
                    $box = $boxRepo->find($item['box']->getId());
                    if (!$box) {
                        continue;
                    }
                    $lineItems[] = [
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => ['name' => 'Box Personnalisée'],
                            'unit_amount' => $this->enCentimes($box->getPrix()),
                        ],
                        'quantity' => 1,
                    ];
                }
            }

            if (empty($lineItems)) {
                return new JsonResponse(['error' => 'Aucun article à commander'], 400);
            }

            // ✅ Frais de livraison : même service que l'affichage, ajoutés au montant Stripe
            $sousTotalCentimes = 0;
            foreach ($lineItems as $lineItem) {
                $sousTotalCentimes += $lineItem['price_data']['unit_amount'] * $lineItem['quantity'];
            }

            $fraisCentimes = $this->fraisLivraisonService->calculer(
                $sousTotalCentimes,
                FraisLivraisonService::MODE_LIVRAISON
            );

            if ($fraisCentimes > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => ['name' => 'Livraison'],
                        'unit_amount' => $fraisCentimes,
                    ],
                    'quantity' => 1,
                ];
            }

            // ✅ Récupérer l'adresse choisie depuis la session
            $session = $this->container->get('request_stack')->getSession();
            $adresseId = $session->get('adresse_livraison_id');
            $session->set('adresse_livraison_id', $adresseId);

            $checkoutSession = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $this->generateUrl('app_paiement_success_stripe', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->generateUrl('app_panier_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'customer_email' => $user ? $user->getEmail() : null,
            ]);

            return new JsonResponse(['sessionId' => $checkoutSession->id]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ✅ successStripe — affichage uniquement, la commande est créée par le webhook
    #[Route('/paiement/success-stripe', name: 'app_paiement_success_stripe')]
    public function successStripe(
        Request $request,
        CommandeRepository $commandeRepo
    ): Response
    {
        $sessionId = $request->query->get('session_id');

        if (!$sessionId) {
            $this->addFlash('error', 'Session de paiement invalide');
            return $this->redirectToRoute('app_home');
        }

        $session = $this->container->get('request_stack')->getSession();
        $session->remove('panier');

        // Attendre max 5 secondes que le webhook crée la commande
        $commande = null;
        for ($i = 0; $i < 5; $i++) {
            $commande = $commandeRepo->findOneBy(['stripeSessionId' => $sessionId]);
            if ($commande) break;
            sleep(1);
        }

        if ($commande) {
            $session->set('commande_success', [
                'numero' => $commande->getNumeroCommande(),
                'total' => $commande->getTotalTTC(),
                'date' => $commande->getDateCommande(),
                'email' => $commande->getUser()?->getEmail()
            ]);
        }

        // ✅ Nettoyer l'adresse de session
        $session->remove('adresse_livraison_id');

        return $this->redirectToRoute('app_home');
    }

    #[Route('/clear-commande-session', name: 'app_clear_commande_session', methods: ['POST'])]
    public function clearCommandeSession(): JsonResponse
    {
        $session = $this->container->get('request_stack')->getSession();
        $session->remove('commande_success');
        return new JsonResponse(['success' => true]);
    }

    #[Route('/paiement/cancel', name: 'app_paiement_cancel')]
    public function cancel(): Response
    {
        $this->addFlash('warning', 'Le paiement a été annulé');
        return $this->redirectToRoute('app_panier_index');
    }

    // ✅ Sauvegarder l'adresse choisie en session
    #[Route('/paiement/choisir-adresse', name: 'app_paiement_choisir_adresse', methods: ['POST'])]
    public function choisirAdresse(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $adresseId = $data['adresse_id'] ?? null;

        if (!$adresseId) {
            return new JsonResponse(['error' => 'Adresse invalide'], 400);
        }

        $session = $this->container->get('request_stack')->getSession();
        $session->set('adresse_livraison_id', $adresseId);

        return new JsonResponse(['success' => true]);
    }
}