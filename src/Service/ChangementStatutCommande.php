<?php

namespace App\Service;

use App\Entity\Commande;
use App\Enum\CommandeStatut;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Point de passage UNIQUE pour changer le statut d'une commande : seules les transitions du workflow « commande »
 * (config/packages/workflow.yaml) sont possibles. Le changement est ensuite enregistré dans l'historique
 * (voir EnregistreurHistoriqueStatut) avec la source et l'auteur du contexte.
 */
class ChangementStatutCommande
{
    public function __construct(
        private Registry $workflows,
        private ContexteChangementStatut $contexte
    ) {
    }

    /**
     * @throws \LogicException si le workflow n'autorise pas ce changement de statut
     */
    public function appliquer(Commande $commande, CommandeStatut $vers, string $source, ?string $auteur = null, ?string $note = null): void
    {
        $de = $commande->getStatut();
        if ($de === $vers) {
            return;
        }

        $transition = $this->transition($commande, $de, $vers);
        if ($transition === null) {
            throw new \LogicException(sprintf(
                'Changement de statut interdit : « %s » → « %s ».',
                $de?->getLibelle() ?? '?',
                $vers->getLibelle()
            ));
        }

        $this->contexte->definir($source, $auteur, $note);
        $this->workflow($commande)->apply($commande, $transition->getName());
    }

    /** Statuts pour lesquels de l'argent a été encaissé (ou reçu) : annuler une telle commande oblige à penser au remboursement. */
    private const STATUTS_ARGENT_ENCAISSE = [
        CommandeStatut::PAYEE,
        CommandeStatut::CONFIRMEE,
        CommandeStatut::EN_PREPARATION,
        CommandeStatut::A_VERIFIER,
        CommandeStatut::RETOURNEE,
    ];

    /**
     * Message à afficher (et à inscrire dans l'historique) quand on annule une commande dont le paiement a été encaissé :
     * l'annulation ne rembourse PAS, le remboursement se fait dans le tableau de bord Stripe.
     */
    public function avertissementAnnulation(?CommandeStatut $de, CommandeStatut $vers): ?string
    {
        if ($vers !== CommandeStatut::ANNULEE || $de === null || !in_array($de, self::STATUTS_ARGENT_ENCAISSE, true)) {
            return null;
        }

        return 'Cette commande est payée : pense à la rembourser dans Stripe.';
    }
    /** Ce changement est-il autorisé par le workflow ? (même statut = rien à faire = autorisé) */
    public function peutAller(Commande $commande, ?CommandeStatut $de, CommandeStatut $vers): bool
    {
        return $de === $vers || ($de !== null && $this->transition($commande, $de, $vers) !== null);
    }

    /** @return list<CommandeStatut> les statuts atteignables depuis le statut actuel */
    public function statutsPossibles(Commande $commande): array
    {
        $possibles = [];
        foreach ($this->workflow($commande)->getEnabledTransitions($commande) as $transition) {
            foreach ($transition->getTos() as $place) {
                $possibles[] = CommandeStatut::from($place);
            }
        }

        return $possibles;
    }

    private function workflow(Commande $commande): WorkflowInterface
    {
        return $this->workflows->get($commande, 'commande');
    }

    /** La transition qui mène de $de à $vers depuis cet état (null si aucune). */
    private function transition(Commande $commande, ?CommandeStatut $de, CommandeStatut $vers): ?Transition
    {
        if ($de === null) {
            return null;
        }

        foreach ($this->workflow($commande)->getDefinition()->getTransitions() as $transition) {
            if (in_array($de->value, $transition->getFroms(), true) && in_array($vers->value, $transition->getTos(), true)) {
                return $transition;
            }
        }

        return null;
    }
}
