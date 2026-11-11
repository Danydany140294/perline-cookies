<?php

namespace App\Command;

use App\Service\PaiementCommandeService;
use App\Service\StripeEvenements;
use App\Service\TraitementEvenementStripe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stripe-rejouer-evenement',
    description: 'Rejoue un événement Stripe (webhook perdu ou en retard) : relu chez Stripe, traité comme le webhook, idempotent',
)]
class StripeRejouerEvenementCommand extends Command
{
    private const TYPES_ACCEPTES = ['checkout.session.completed', 'checkout.session.expired'];

    public function __construct(
        private StripeEvenements $stripeEvenements,
        private TraitementEvenementStripe $traitement
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('evenement', InputArgument::REQUIRED, 'Identifiant de l\'événement Stripe (evt_...)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche l\'événement relu chez Stripe sans le traiter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('evenement');

        if (!preg_match('/^evt_[A-Za-z0-9]+$/', $id)) {
            $io->error('Identifiant invalide : attendu « evt_ » suivi de lettres et chiffres.');

            return Command::FAILURE;
        }

        try {
            $event = $this->stripeEvenements->recuperer($id);
        } catch (\Throwable $e) {
            $io->error('Impossible de relire l\'événement chez Stripe : ' . $e->getMessage());

            return Command::FAILURE;
        }

        if (!in_array($event->type, self::TYPES_ACCEPTES, true)) {
            $io->error(sprintf('Type d\'événement non géré : %s (acceptés : %s).', $event->type, implode(', ', self::TYPES_ACCEPTES)));

            return Command::FAILURE;
        }

        $session = $event->data->object;
        $io->definitionList(
            ['Événement' => $event->id . ' (' . $event->type . ')'],
            ['Session Stripe' => $session->id ?? '?'],
            ['Commande (client_reference_id)' => $session->client_reference_id ?? '(aucune)'],
            ['Statut du paiement' => $session->payment_status ?? '?'],
            ['Montant' => number_format(((int) ($session->amount_total ?? 0)) / 100, 2, ',', ' ') . ' ' . strtoupper((string) ($session->currency ?? ''))]
        );

        if ($input->getOption('dry-run')) {
            $io->note('Simulation : rien n\'a été traité.');

            return Command::SUCCESS;
        }

        $resultat = $this->traitement->traiter($event);

        return match ($resultat) {
            PaiementCommandeService::PAYEE => $this->succes($io, 'Commande payée : stock retiré, panier vidé, e-mail de confirmation envoyé.'),
            PaiementCommandeService::ANNULEE => $this->succes($io, 'Commande annulée (session expirée).'),
            PaiementCommandeService::DEJA_TRAITEE => $this->succes($io, 'Déjà traité : rien n\'a changé (idempotence).'),
            PaiementCommandeService::NON_PAYEE, PaiementCommandeService::IGNOREE, TraitementEvenementStripe::IGNORE => $this->succes($io, sprintf('Aucun changement (%s).', $resultat)),
            PaiementCommandeService::A_VERIFIER => $this->attention($io, 'Commande passée « À vérifier » (écart de montant, de devise ou de stock) : voir les logs critiques.'),
            default => $this->echec($io, sprintf('Non traité (%s) : voir les logs.', $resultat)),
        };
    }

    private function succes(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    private function attention(SymfonyStyle $io, string $message): int
    {
        $io->warning($message);

        return Command::SUCCESS;
    }

    private function echec(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }
}
