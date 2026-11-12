<?php

namespace App\Twig;

use App\Entity\Commande;
use App\Service\DelaisLivraison;
use App\Service\SuiviColis;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Délais de livraison dans les templates (CGV, FAQ...) : toujours calculés d'après la configuration. */
class LivraisonExtension extends AbstractExtension
{
    public function __construct(private DelaisLivraison $delais, private SuiviColis $suivi)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jours_expedition', fn (): string => $this->delais->joursExpeditionTexte()),
            new TwigFunction('livraison_estimee', fn (): string => $this->delais->phrase(new \DateTimeImmutable())),
            new TwigFunction('lien_suivi', fn (Commande $commande): ?string => $this->suivi->lien($commande)),
        ];
    }
}
