<?php

namespace App\Command;

use App\Entity\Commande;
use App\Enum\CommandeStatut;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:nettoyer-commandes-en-attente',
    description: 'Supprime les commandes « En attente » abandonnées (jamais les « Annulées », « À vérifier » ni « Payées »)',
)]
class NettoyerCommandesEnAttenteCommand extends Command
{
    // Une session Stripe Checkout reste payable 24 h au maximum. On attend 48 h : une commande
    // supprimée alors que sa session est encore payable ferait un paiement « orphelin ».
    private const DELAI_PAR_DEFAUT_HEURES = 48;
    private const DELAI_MINIMUM_HEURES = 25;

    public function __construct(
        private CommandeRepository $commandeRepository,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('heures', null, InputOption::VALUE_REQUIRED, 'Âge minimum (en heures) avant suppression', (string) self::DELAI_PAR_DEFAUT_HEURES)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait supprimé, sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $heures = (int) $input->getOption('heures');
        if ($heures < self::DELAI_MINIMUM_HEURES) {
            $io->error(sprintf('Délai trop court (%d h) : une session Stripe reste payable 24 h. Minimum : %d h.', $heures, self::DELAI_MINIMUM_HEURES));

            return Command::FAILURE;
        }

        $limite = (new \DateTime())->modify(sprintf('-%d hours', $heures));
        // Double sécurité : seules les « En attente » sont jamais supprimées (jamais « Annulée »,
        // « À vérifier » ni « Payée »), quoi que renvoie la requête.
        $commandes = array_values(array_filter(
            $this->commandeRepository->findEnAttenteAvant($limite),
            static fn (Commande $c): bool => $c->getStatut() === CommandeStatut::EN_ATTENTE
        ));

        if ($commandes === []) {
            $io->info('Aucune commande « En attente » abandonnée à supprimer.');

            return Command::SUCCESS;
        }

        foreach ($commandes as $commande) {
            $io->writeln(sprintf(
                '- %s (id %d, %s €, créée le %s)',
                $commande->getNumeroCommande(),
                $commande->getId(),
                number_format((float) $commande->getTotalTTC(), 2, ',', ' '),
                $commande->getDateCommande()?->format('d/m/Y H:i')
            ));
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Simulation : %d commande(s) seraient supprimées.', count($commandes)));

            return Command::SUCCESS;
        }

        foreach ($commandes as $commande) {
            $this->em->remove($commande); // cascade : lignes et compositions
        }
        $this->em->flush();

        $io->success(sprintf('%d commande(s) « En attente » supprimée(s).', count($commandes)));

        return Command::SUCCESS;
    }
}
