<?php

namespace App\Controller;

use App\Entity\CompositionBoxPersonnalisable;
use App\Entity\LigneCommande;
use App\Enum\CommandeStatut;
use App\Enum\ModeLivraison;
use App\Repository\AdresseRepository;
use App\Repository\CommandeRepository;
use App\Repository\PanierRepository;
use App\Repository\LignePanierRepository;
use App\Service\CommandeService;
use App\Service\FraisLivraisonService;
use App\Service\StockCookiesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

    public function __construct(
        private FraisLivraisonService $fraisLivraisonService,
        #[Autowire(param: 'retrait')] private array $retrait
    )
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
        $this->generateUrl('app_panier_index')
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
        $totalRetrait = $sousTotalCentimes / 100; // retrait : frais à 0 €
        $stripePublicKey = $_ENV['STRIPE_PUBLIC_KEY'] ?? '';

        // ✅ Récupérer les adresses de l'user (par défaut en premier)
        $adresses = $adresseRepo->findBy(['user' => $user], ['parDefaut' => 'DESC']);

        return $this->render('Page/paiement.html.twig', [
            'show_success_modal' => false,
            'panier' => $panierData,
            'frais_livraison' => $fraisLivraison,
            'total_final' => $totalFinal,
            'total_retrait' => $totalRetrait,
            'retrait' => $this->retrait,
            'stripe_public_key' => $stripePublicKey,
            'adresses' => $adresses,
        ]);
    }

    #[Route('/paiement/create-checkout-session', name: 'app_paiement_create_session', methods: ['POST'])]
    public function createCheckoutSession(
        Request $request,
        PanierRepository $panierRepo,
        LignePanierRepository $lignePanierRepo,
        CommandeService $commandeService,
        AdresseRepository $adresseRepo,
        StockCookiesService $stockCookies,
        EntityManagerInterface $em
    ): JsonResponse
    {
        // Compte obligatoire pour payer (achat invité : prévu plus tard, avec e-mail et lien de suivi).
        $user = $this->getUser();
        if (!$user) {
            $this->saveTargetPath($request->getSession(), 'main', $this->generateUrl('app_panier_index'));

            return new JsonResponse([
                'error' => 'Connectez-vous pour finaliser votre commande.',
                'redirect' => $this->generateUrl('app_login'),
            ], 401);
        }

        // Mode choisi par le client (jamais fait confiance à autre chose que les deux valeurs connues)
        $donnees = json_decode($request->getContent(), true);
        $mode = ModeLivraison::tryFrom((string) ($donnees['mode'] ?? ModeLivraison::LIVRAISON->value));
        if ($mode === null) {
            return new JsonResponse(['error' => 'Mode de livraison invalide'], 400);
        }

        // Livraison : une adresse du client est obligatoire. Retrait : aucune adresse.
        $adresse = null;
        if ($mode === ModeLivraison::LIVRAISON) {
            $adresseId = $request->getSession()->get('adresse_livraison_id');
            $adresse = $adresseId ? $adresseRepo->find($adresseId) : null;

            if ($adresse === null || $adresse->getUser()?->getId() !== $user->getId()) {
                return new JsonResponse(['error' => 'Veuillez choisir une adresse de livraison.'], 400);
            }
        }

        $commande = null; // commande « En attente » créée pour le client connecté

        try {
            $lineItems = [];
            $panier = $panierRepo->findOneBy(['user' => $user]);

            if (!$panier) {
                return new JsonResponse(['error' => 'Panier introuvable'], 404);
            }

            $lignes = $lignePanierRepo->findBy(['panier' => $panier]);

            if (empty($lignes)) {
                return new JsonResponse(['error' => 'Votre panier est vide'], 400);
            }

            // Boxes encore vendables, puis stock : cookies seuls + cookies des boxes classiques + box perso, cumulés.
            // Blocage AVANT toute création de commande, avec le détail du cookie en cause.
            $problemesStock = [
                ...$stockCookies->verifierBoxes($lignes),
                ...$stockCookies->verifier($stockCookies->besoinsDepuisPanier($lignes)),
            ];
            if ($problemesStock !== []) {
                return new JsonResponse([
                    'error' => implode("\n", $problemesStock),
                    'stock_insuffisant' => $problemesStock,
                ], 409);
            }

            // Commande « En attente » : lignes figées au moment où le client va payer.
            // Le webhook comparera le montant payé à CETTE commande, pas au panier du moment.
            $commande = $commandeService->creerCommande($user);
            $commande->setModeLivraison($mode);
            $commande->setAdresseLivraison($adresse); // null en retrait

            foreach ($lignes as $ligne) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => ['name' => $ligne->getNomArticle()],
                        'unit_amount' => $this->enCentimes($ligne->getPrixUnitaire()),
                    ],
                    'quantity' => $ligne->getQuantite(),
                ];

                $ligneCommande = new LigneCommande();
                $ligneCommande->setProduit($ligne->getProduit());
                $ligneCommande->setBox($ligne->getBox());
                $ligneCommande->setQuantite($ligne->getQuantite());
                $ligneCommande->setPrixUnitaire($ligne->getPrixUnitaire());

                // Composition FIGÉE sur la ligne (cookies et quantités PAR BOX) : box perso = choix du
                // client, box classique = recette au moment de la commande. Modifier la recette plus tard
                // ne change jamais cette commande, et le stock sera retiré d'après ces lignes.
                if ($ligne->isBoxPersonnalisable()) {
                    foreach ($ligne->getCompositionsPanier() as $compo) {
                        $compoBox = new CompositionBoxPersonnalisable();
                        $compoBox->setProduit($compo->getProduit());
                        $compoBox->setQuantite($compo->getQuantite());
                        $ligneCommande->addCompositionBox($compoBox);
                    }
                } elseif ($ligne->getBox() !== null) {
                    foreach ($ligne->getBox()->getCompositions() as $composition) {
                        $compoBox = new CompositionBoxPersonnalisable();
                        $compoBox->setProduit($composition->getProduit());
                        $compoBox->setQuantite($composition->getQuantite());
                        $ligneCommande->addCompositionBox($compoBox);
                    }
                }

                $commande->addLigneCommande($ligneCommande);
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
                $mode === ModeLivraison::RETRAIT ? FraisLivraisonService::MODE_RETRAIT : FraisLivraisonService::MODE_LIVRAISON
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


            // ✅ Enregistrer la commande « En attente » (total = articles + livraison, en euros)
            //    pour obtenir son id, transmis à Stripe via client_reference_id.
            if ($commande !== null) {
                $commande->setTotalTTC(($sousTotalCentimes + $fraisCentimes) / 100);
                $em->persist($commande);
                $em->flush();
            }

            $parametresStripe = [
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $this->generateUrl('app_paiement_success_stripe', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->generateUrl('app_panier_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'customer_email' => $user ? $user->getEmail() : null,
            ];

            if ($commande !== null) {
                $parametresStripe['client_reference_id'] = (string) $commande->getId();
            }

            try {
                $checkoutSession = \Stripe\Checkout\Session::create($parametresStripe);
            } catch (\Throwable $e) {
                // Stripe a refusé : la commande « En attente » ne servirait à rien.
                if ($commande !== null) {
                    $em->remove($commande);
                    $em->flush();
                }
                throw $e;
            }

            if ($commande !== null) {
                $commande->setStripeSessionId($checkoutSession->id);
                $em->flush();
            }

            return new JsonResponse(['sessionId' => $checkoutSession->id]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ✅ successStripe — affichage uniquement, la commande est créée par le webhook
    #[Route('/paiement/success-stripe', name: 'app_paiement_success_stripe')]
    public function successStripe(
        Request $request,
        CommandeRepository $commandeRepo,
        EntityManagerInterface $em
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
            if ($commande) {
                // La commande « En attente » existe dès la création du paiement : on attend
                // que le webhook l'ait fait passer à « Payée » (ou « À vérifier »).
                $em->refresh($commande);
                if ($commande->getStatut() !== CommandeStatut::EN_ATTENTE) {
                    break;
                }
            }
            sleep(1);
        }

        // Une commande rattachée à un compte n'est visible que par son propriétaire.
        // Toute commande a désormais un propriétaire (compte obligatoire pour payer) :
        // une commande sans propriétaire est donc refusée aussi.
        $proprietaire = $commande?->getUser();
        if ($commande !== null && ($proprietaire === null || $proprietaire->getId() !== $this->getUser()?->getId())) {
            $this->addFlash('error', 'Cette commande ne vous appartient pas.');
            return $this->redirectToRoute('app_home');
        }

        // Webhook en retard : la commande est encore « En attente ». Pas de faux « Félicitations ».
        if ($commande && $commande->getStatut() === CommandeStatut::EN_ATTENTE) {
            $this->addFlash('warning', 'Votre paiement est en cours de confirmation. Vous recevrez un e-mail dès qu\'il sera validé.');
            return $this->redirectToRoute('app_home');
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
