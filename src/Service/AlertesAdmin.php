<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\Produit;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Alertes envoyées à l'administration (adresse `email_alertes` de config/boutique.yaml, par défaut l'e-mail de contact) :
 * commande payée, stock sous le seuil. Un échec d'envoi est journalisé : il ne doit jamais faire échouer un paiement.
 */
class AlertesAdmin
{
    public function __construct(
        private MailerInterface $mailer,
        private IdentiteBoutique $boutique,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function commandePayee(Commande $commande): void
    {
        $lien = $this->lienAdmin('admin_dashboard_commande_detail', $commande->getId());
        $this->envoyer(
            'Commande payée ' . $commande->getNumeroCommande(),
            sprintf(
                "Une commande vient d'être payée.\n\nNuméro : %s\nClient : %s (%s)\nArticles : %d\nTotal TTC : %s €\nMode : %s\n\nVoir la commande : %s\n",
                $commande->getNumeroCommande(),
                $commande->getUser()?->getNomComplet() ?? '—',
                $commande->getUser()?->getEmail() ?? '—',
                $commande->getNombreTotalArticles(),
                number_format((float) $commande->getTotalTTC(), 2, ',', ' '),
                $commande->isRetrait() ? 'retrait sur place' : 'livraison à domicile',
                $lien
            )
        );
    }

    public function stockSousLeSeuil(Produit $produit, int $stock, int $seuil): void
    {
        $this->envoyer(
            sprintf('Stock %s : %s', $stock <= 0 ? 'épuisé' : 'faible', $produit->getName()),
            sprintf(
                "Le stock de « %s » a atteint son seuil d'alerte.\n\nStock actuel : %d\nSeuil d'alerte : %d\n\nRéapprovisionner : %s\n",
                $produit->getName(),
                $stock,
                $seuil,
                $this->lienAdmin('admin_dashboard_produit_edit', $produit->getId())
            )
        );
    }

    private function lienAdmin(string $route, ?int $id): string
    {
        try {
            return $this->urls->generate($route, ['entityId' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return '(ouvrez l\'administration)';
        }
    }

    private function envoyer(string $sujet, string $texte): void
    {
        $destinataire = $this->boutique->emailAlertes();
        if ($destinataire === '') {
            return;
        }
        try {
            $this->mailer->send((new Email())->from($this->boutique->expediteur())->to($destinataire)->subject('[Alerte] ' . $sujet)->text($texte));
        } catch (\Throwable $e) {
            $this->logger->error('Alerte administration : e-mail non envoyé.', ['sujet' => $sujet, 'erreur' => $e->getMessage()]);
        }
    }
}
