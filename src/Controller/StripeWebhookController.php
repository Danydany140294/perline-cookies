<?php

namespace App\Controller;

use App\Entity\Box;
use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\CompositionBoxPersonnalisable;
use App\Entity\Produit;
use App\Enum\CommandeStatut;
use App\Repository\CommandeRepository;
use App\Repository\PanierRepository;
use App\Repository\LignePanierRepository;
use App\Service\PaiementCommandeService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private PaiementCommandeService $paiementCommandeService,
        private LoggerInterface $logger
    ) {}

    /**
     * E-mail de confirmation (client connecté). Un échec d'envoi n'annule pas la commande
     * mais il est désormais écrit dans les logs au lieu d'être avalé en silence.
     */
    private function envoyerConfirmation(Commande $commande): void
    {
        try {
            $html = $this->twig->render('emails/confirmation_commande.html.twig', [
                'commande' => $commande,
            ]);

            $this->mailer->send(
                (new Email())
                    ->from('noreply@creaself.fr')
                    ->to($commande->getUser()->getEmail())
                    ->subject('Confirmation de votre commande ' . $commande->getNumeroCommande())
                    ->html($html)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Échec de l\'envoi de l\'e-mail de confirmation de commande.', [
                'numero' => $commande->getNumeroCommande(),
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    #[Route('/webhook/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
    public function handleWebhook(
        Request $request,
        EntityManagerInterface $em,
        CommandeRepository $commandeRepo,
        PanierRepository $panierRepo,
        LignePanierRepository $lignePanierRepo
    ): JsonResponse
    {
        $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('stripe-signature');

        // ✅ Vérifier la signature Stripe
        try {
            \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Signature invalide'], 400);
        }

        // ✅ Session expirée — paiement abandonné
        if ($event->type === 'checkout.session.expired') {
            return new JsonResponse(['status' => 'session_expired']);
        }

        // ✅ On traite uniquement le paiement confirmé
        if ($event->type !== 'checkout.session.completed') {
            return new JsonResponse(['status' => 'ignored']);
        }

        $stripeSession = $event->data->object;

        // ✅ Parcours normal (client connecté) : la commande « En attente » existe déjà,
        //    son id nous revient dans client_reference_id. On la vérifie puis on la finalise.
        $commandeId = $stripeSession->client_reference_id ?? null;
        if ($commandeId !== null && ctype_digit((string) $commandeId)) {
            [$resultat, $commande] = $this->paiementCommandeService->finaliser(
                (int) $commandeId,
                (string) $stripeSession->id,
                (string) $stripeSession->payment_status,
                $stripeSession->currency ?? null,
                (int) $stripeSession->amount_total
            );

            if ($resultat === PaiementCommandeService::PAYEE && $commande?->getUser()) {
                $this->envoyerConfirmation($commande);
            }

            // Toujours 200 : un échec fonctionnel (ex. commande introuvable) est déjà journalisé,
            // et un 500 ferait seulement réessayer Stripe pour rien.
            return new JsonResponse(['status' => $resultat]);
        }

        // ─── Ancien parcours (invités, sans commande préalable) : inchangé, à revoir au point E ───

        // ✅ Éviter les doublons (si webhook reçu deux fois)
        $existing = $commandeRepo->findOneBy(['stripeSessionId' => $stripeSession->id]);
        if ($existing) {
            return new JsonResponse(['status' => 'already_processed']);
        }

        // ✅ Vérifier que le paiement est bien payé
        if ($stripeSession->payment_status !== 'paid') {
            return new JsonResponse(['status' => 'not_paid']);
        }

        // ✅ Créer la commande
        $commande = new Commande();
        $commande->setStripeSessionId($stripeSession->id);
        $commande->setNumeroCommande('CMD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)));
        $commande->setDateCommande(new \DateTime());
        $commande->setStatut(CommandeStatut::PAYEE);
        $commande->setTotalTTC($stripeSession->amount_total / 100);

        // ✅ Retrouver le user via l'email Stripe
        $stripeEmail = $stripeSession->customer_email;
        if ($stripeEmail) {
            $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $stripeEmail]);
            if ($user) {
                $commande->setUser($user);

                // ✅ Récupérer et vider le panier BDD
                $panier = $panierRepo->findOneBy(['user' => $user]);
                if ($panier) {
                    $lignesPanier = $lignePanierRepo->findBy(['panier' => $panier]);

                    foreach ($lignesPanier as $lignePanier) {
                        $ligneCommande = new LigneCommande();
                        $ligneCommande->setCommande($commande);
                        $ligneCommande->setPrixUnitaire($lignePanier->getPrixUnitaire());
                        $ligneCommande->setQuantite($lignePanier->getQuantite());

                        if ($lignePanier->getProduit()) {
                            $ligneCommande->setProduit($lignePanier->getProduit());
                            // ✅ Décrémenter le stock du produit
                            $lignePanier->getProduit()->decrémenterStock($lignePanier->getQuantite());
                        }

                        if ($lignePanier->getBox()) {
                            $ligneCommande->setBox($lignePanier->getBox());
                        }

                        // ✅ Compositions box perso
                        if ($lignePanier->isBoxPersonnalisable()) {
                            foreach ($lignePanier->getCompositionsPanier() as $compo) {
                                $compoBox = new CompositionBoxPersonnalisable();
                                $compoBox->setProduit($compo->getProduit());
                                $compoBox->setQuantite($compo->getQuantite());
                                $compoBox->setLigneCommande($ligneCommande);
                                $em->persist($compoBox);
                                $ligneCommande->addCompositionBox($compoBox);
                            }
                        }

                        $em->persist($ligneCommande);
                    }

                    // ✅ Vider le panier
                    foreach ($lignesPanier as $ligne) {
                        $em->remove($ligne);
                    }
                    $em->remove($panier);
                }
            }
        }

        // Un seul flush() = une seule transaction : commande, lignes, stock et panier
        // sont enregistrés ensemble ou pas du tout.
        try {
            $em->persist($commande);
            $em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Deux livraisons simultanées du même événement : l'autre requête a gagné.
            // L'EntityManager est fermé après une erreur de flush : on ne l'utilise plus,
            // seule la connexion SQL (toujours valide) sert à vérifier la situation.
            $dejaEnregistree = (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM commande WHERE stripe_session_id = ?',
                [$stripeSession->id]
            );

            if ($dejaEnregistree === 0) {
                throw $e; // doublon sur autre chose que la session Stripe : on ne l'avale pas
            }

            return new JsonResponse(['status' => 'already_processed']);
        }

        // ✅ Envoyer l'email de confirmation
        if ($commande->getUser()) {
            try {
                $html = $this->twig->render('emails/confirmation_commande.html.twig', [
                    'commande' => $commande
                ]);

                $emailMessage = (new Email())
                    ->from('noreply@creaself.fr')
                    ->to($commande->getUser()->getEmail())
                    ->subject('Confirmation de votre commande ' . $commande->getNumeroCommande())
                    ->html($html);

                $this->mailer->send($emailMessage);
            } catch (\Exception $e) {
                // Silencieux — ne pas bloquer la commande si l'email échoue
            }
        }

        return new JsonResponse(['status' => 'success']);
    }
}