<?php

namespace App\Command;

use App\Entity\Commande;
use App\Repository\FactureRepository;
use App\Service\Facturation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Rattrape les commandes payées qui n'ont pas de facture (émission échouée) et les avoirs manquants. Jamais de doublon. */
#[AsCommand(name: 'app:factures:emettre', description: 'Émet les factures et avoirs manquants des commandes payées')]
class EmettreFacturesManquantesCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private Facturation $facturation, private FactureRepository $factures)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $emises = 0;
        foreach ($this->em->getRepository(Commande::class)->findBy([], ['id' => 'ASC']) as $commande) {
            if ($this->facturation->estFacturable($commande) && $this->factures->factureDeLaCommande($commande) === null) {
                $facture = $this->facturation->emettreFacture($commande);
                $output->writeln('Facture ' . $facture?->getNumero() . ' émise pour ' . $commande->getNumeroCommande());
                ++$emises;
            }
            if ($commande->getMontantRembourse() > 0 && ($avoir = $this->facturation->emettreAvoir($commande)) !== null) {
                $output->writeln('Avoir ' . $avoir->getNumero() . ' émis pour ' . $commande->getNumeroCommande());
                ++$emises;
            }
        }
        $output->writeln($emises . ' document(s) émis.');

        return Command::SUCCESS;
    }
}
