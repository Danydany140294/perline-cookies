<?php

namespace App\Service;

use App\Entity\Commande;
use App\Enum\CommandeStatut;
use App\Enum\ModeLivraison;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/** E-mail « Votre commande est expédiée » : une seule fois par commande, livraison à domicile uniquement. */
class EmailExpeditionService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private EntityManagerInterface $em,
        private SuiviColis $suivi,
        private LoggerInterface $logger,
        private IdentiteBoutique $boutique,
    ) {
    }

    /** @return bool true si un e-mail a été envoyé */
    public function envoyer(Commande $commande): bool
    {
        if ($commande->getStatut() !== CommandeStatut::EXPEDIEE
            || $commande->getModeLivraison() !== ModeLivraison::LIVRAISON
            || $commande->getEmailExpeditionEnvoyeLe() !== null
            || !$commande->getUser()?->getEmail()) {
            return false;
        }

        try {
            $html = $this->twig->render('emails/expedition_commande.html.twig', [
                'commande' => $commande,
                'lien_suivi' => $this->suivi->lien($commande),
            ]);
            $this->mailer->send(
                (new Email())
                    ->from($this->boutique->expediteur())
                    ->to($commande->getUser()->getEmail())
                    ->subject('Votre commande ' . $commande->getNumeroCommande() . ' est expédiée')
                    ->html($html)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Échec de l\'envoi de l\'e-mail d\'expédition.', ['numero' => $commande->getNumeroCommande(), 'erreur' => $e->getMessage()]);

            return false; // pas de date : un nouvel essai reste possible
        }

        $commande->setEmailExpeditionEnvoyeLe(new \DateTimeImmutable());
        $this->em->flush();

        return true;
    }
}
