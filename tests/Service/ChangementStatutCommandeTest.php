<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Enum\CommandeStatut;
use App\Tests\Support\AvecWorkflow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Transitions du workflow « commande » (config/packages/workflow.yaml), testées sur la vraie configuration. */
class ChangementStatutCommandeTest extends KernelTestCase
{
    use AvecWorkflow;

    private function commande(CommandeStatut $statut): Commande
    {
        return (new Commande())->setStatut($statut);
    }

    /** @return iterable<string, array{CommandeStatut, CommandeStatut}> */
    public static function transitionsAutorisees(): iterable
    {
        yield 'paiement' => [CommandeStatut::EN_ATTENTE, CommandeStatut::PAYEE];
        yield 'écart de paiement' => [CommandeStatut::EN_ATTENTE, CommandeStatut::A_VERIFIER];
        yield 'session expirée' => [CommandeStatut::EN_ATTENTE, CommandeStatut::ANNULEE];
        yield 'paiement tardif sur annulée' => [CommandeStatut::ANNULEE, CommandeStatut::A_VERIFIER];
        yield 'vérification acceptée' => [CommandeStatut::A_VERIFIER, CommandeStatut::PAYEE];
        yield 'vérification refusée' => [CommandeStatut::A_VERIFIER, CommandeStatut::ANNULEE];
        yield 'confirmation' => [CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE];
        yield 'préparation depuis payée' => [CommandeStatut::PAYEE, CommandeStatut::EN_PREPARATION];
        yield 'préparation depuis confirmée' => [CommandeStatut::CONFIRMEE, CommandeStatut::EN_PREPARATION];
        yield 'expédition' => [CommandeStatut::EN_PREPARATION, CommandeStatut::EXPEDIEE];
        yield 'livraison' => [CommandeStatut::EXPEDIEE, CommandeStatut::LIVREE];
        yield 'retrait sur place' => [CommandeStatut::EN_PREPARATION, CommandeStatut::LIVREE];
        yield 'annulation d\'une payée' => [CommandeStatut::PAYEE, CommandeStatut::ANNULEE];
        yield 'remboursement d\'une payée' => [CommandeStatut::PAYEE, CommandeStatut::REMBOURSEE];
        yield 'remboursement d\'une livrée' => [CommandeStatut::LIVREE, CommandeStatut::REMBOURSEE];
        yield 'remboursement d\'une annulée' => [CommandeStatut::ANNULEE, CommandeStatut::REMBOURSEE];
        yield 'remboursement d\'une à vérifier' => [CommandeStatut::A_VERIFIER, CommandeStatut::REMBOURSEE];
        yield 'colis retourné' => [CommandeStatut::EXPEDIEE, CommandeStatut::RETOURNEE];
        yield 'réexpédition après retour' => [CommandeStatut::RETOURNEE, CommandeStatut::EN_PREPARATION];
        yield 'remboursement après retour' => [CommandeStatut::RETOURNEE, CommandeStatut::REMBOURSEE];
        yield 'annulation après retour' => [CommandeStatut::RETOURNEE, CommandeStatut::ANNULEE];
    }

    /** @return iterable<string, array{CommandeStatut, CommandeStatut}> */
    public static function transitionsInterdites(): iterable
    {
        yield 'sauter la préparation' => [CommandeStatut::PAYEE, CommandeStatut::EXPEDIEE];
        yield 'livrer sans préparer' => [CommandeStatut::PAYEE, CommandeStatut::LIVREE];
        yield 'revenir en arrière (livrée -> en attente)' => [CommandeStatut::LIVREE, CommandeStatut::EN_ATTENTE];
        yield 'revenir en arrière (livrée -> payée)' => [CommandeStatut::LIVREE, CommandeStatut::PAYEE];
        yield 'revenir en arrière (expédiée -> en préparation)' => [CommandeStatut::EXPEDIEE, CommandeStatut::EN_PREPARATION];
        yield 'ressusciter une annulée' => [CommandeStatut::ANNULEE, CommandeStatut::PAYEE];
        yield 'rembourser sans paiement' => [CommandeStatut::EN_ATTENTE, CommandeStatut::REMBOURSEE];
        yield 'confirmer sans paiement' => [CommandeStatut::EN_ATTENTE, CommandeStatut::CONFIRMEE];
        yield 'annuler une livrée' => [CommandeStatut::LIVREE, CommandeStatut::ANNULEE];
        yield 'sortir de remboursée (vers payée)' => [CommandeStatut::REMBOURSEE, CommandeStatut::PAYEE];
        yield 'sortir de remboursée (vers annulée)' => [CommandeStatut::REMBOURSEE, CommandeStatut::ANNULEE];
        yield 'sortir de remboursée (vers en attente)' => [CommandeStatut::REMBOURSEE, CommandeStatut::EN_ATTENTE];
        yield 'retourner une commande non expédiée' => [CommandeStatut::EN_PREPARATION, CommandeStatut::RETOURNEE];
        yield 'retourner une livrée' => [CommandeStatut::LIVREE, CommandeStatut::RETOURNEE];
        yield 'livrer une retournée' => [CommandeStatut::RETOURNEE, CommandeStatut::LIVREE];
    }

    /** @dataProvider transitionsAutorisees */
    public function testTransitionAutorisee(CommandeStatut $de, CommandeStatut $vers): void
    {
        $service = $this->changementStatut();
        $commande = $this->commande($de);

        $this->assertTrue($service->peutAller($commande, $de, $vers));
        $service->appliquer($commande, $vers, 'test');

        $this->assertSame($vers, $commande->getStatut());
        $this->assertSame('test', $this->contexteStatut->source(), 'La source est transmise pour l\'historique');
    }

    /** @dataProvider transitionsInterdites */
    public function testTransitionInterdite(CommandeStatut $de, CommandeStatut $vers): void
    {
        $service = $this->changementStatut();
        $commande = $this->commande($de);

        $this->assertFalse($service->peutAller($commande, $de, $vers));
        try {
            $service->appliquer($commande, $vers, 'test');
            $this->fail('Transition interdite acceptée');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Changement de statut interdit', $e->getMessage());
        }

        $this->assertSame($de, $commande->getStatut(), 'Le statut n\'a pas bougé');
    }

    public function testResterAuMemeStatutNEstPasUnChangement(): void
    {
        $service = $this->changementStatut();
        $commande = $this->commande(CommandeStatut::LIVREE);

        $service->appliquer($commande, CommandeStatut::LIVREE, 'test');

        $this->assertSame(CommandeStatut::LIVREE, $commande->getStatut());
        $this->assertTrue($service->peutAller($commande, CommandeStatut::LIVREE, CommandeStatut::LIVREE));
    }

    public function testUneCommandeRembourseeEstUnCulDeSac(): void
    {
        $service = $this->changementStatut();
        $commande = $this->commande(CommandeStatut::REMBOURSEE);

        $this->assertSame([], $service->statutsPossibles($commande));
    }

    public function testStatutsPossiblesDepuisPayee(): void
    {
        $possibles = $this->changementStatut()->statutsPossibles($this->commande(CommandeStatut::PAYEE));
        $valeurs = array_map(static fn (CommandeStatut $s) => $s->value, $possibles);
        sort($valeurs);

        $this->assertSame(['annulee', 'confirmee', 'en_preparation', 'remboursee'], $valeurs);
    }

    public function testTousLesStatutsDeLEnumSontDesPlacesDuWorkflow(): void
    {
        $service = $this->changementStatut();
        foreach (CommandeStatut::cases() as $statut) {
            $commande = $this->commande($statut);
            $this->assertSame($statut->value, $commande->getStatutValue());
            $service->statutsPossibles($commande); // lève une exception si le statut n'est pas une place du workflow
        }
        $this->addToAssertionCount(1);
    }

    public function testAnnulerUneCommandePayeeAvertitDeLaRembourserDansStripe(): void
    {
        $service = $this->changementStatut();

        foreach ([CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE, CommandeStatut::EN_PREPARATION, CommandeStatut::A_VERIFIER] as $de) {
            $this->assertSame(
                'Cette commande est payée : pense à la rembourser dans Stripe.',
                $service->avertissementAnnulation($de, CommandeStatut::ANNULEE),
                $de->value
            );
        }
    }

    public function testAucunAvertissementQuandAucunArgentNEstEncaisseOuPourUneAutreTransition(): void
    {
        $service = $this->changementStatut();

        $this->assertNull($service->avertissementAnnulation(CommandeStatut::EN_ATTENTE, CommandeStatut::ANNULEE), 'Rien à rembourser');
        $this->assertNull($service->avertissementAnnulation(CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE));
        $this->assertNull($service->avertissementAnnulation(CommandeStatut::PAYEE, CommandeStatut::REMBOURSEE));
        $this->assertNull($service->avertissementAnnulation(null, CommandeStatut::ANNULEE));
    }

    public function testPayeeDepuisEnAttenteEstReserveAuSysteme(): void
    {
        $service = $this->changementStatut();

        $this->assertTrue($service->reserveAuSysteme(CommandeStatut::EN_ATTENTE, CommandeStatut::PAYEE));
        // Le workflow, lui, l'autorise (c'est ce que fait le webhook)
        $this->assertTrue($service->peutAller($this->commande(CommandeStatut::EN_ATTENTE), CommandeStatut::EN_ATTENTE, CommandeStatut::PAYEE));
    }

    public function testAutresPassagesNeSontPasReservesAuSysteme(): void
    {
        $service = $this->changementStatut();

        $this->assertFalse($service->reserveAuSysteme(CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE));
        $this->assertFalse($service->reserveAuSysteme(CommandeStatut::A_VERIFIER, CommandeStatut::PAYEE), 'La validation d\'un paiement à vérifier reste une décision de l\'administrateur');
        $this->assertFalse($service->reserveAuSysteme(CommandeStatut::EN_ATTENTE, CommandeStatut::ANNULEE));
    }

    public function testLAdministrateurNeSeVoitJamaisProposerPayeeDepuisEnAttente(): void
    {
        $choix = $this->changementStatut()->statutsChoisissablesParAdmin($this->commande(CommandeStatut::EN_ATTENTE));

        $this->assertNotContains(CommandeStatut::PAYEE, $choix);
        $this->assertContains(CommandeStatut::ANNULEE, $choix);
        $this->assertContains(CommandeStatut::PAYEE, $this->changementStatut()->statutsChoisissablesParAdmin($this->commande(CommandeStatut::A_VERIFIER)));
    }

    /** @return iterable<string, array{CommandeStatut}> */
    public static function origines(): iterable
    {
        foreach (CommandeStatut::cases() as $statut) {
            yield $statut->value => [$statut];
        }
    }

    /** @dataProvider origines */
    public function testRembourseeEtAVerifierSontReservesAuSystemeDepuisNImporteQuelStatut(CommandeStatut $depuis): void
    {
        $service = $this->changementStatut();

        $this->assertTrue($service->reserveAuSysteme($depuis, CommandeStatut::REMBOURSEE));
        $this->assertTrue($service->reserveAuSysteme($depuis, CommandeStatut::A_VERIFIER));
    }

    public function testLAdministrateurNeSeVoitProposerNiRembourseeNiAVerifier(): void
    {
        $service = $this->changementStatut();

        foreach ([CommandeStatut::PAYEE, CommandeStatut::EXPEDIEE, CommandeStatut::LIVREE, CommandeStatut::ANNULEE, CommandeStatut::EN_ATTENTE] as $statut) {
            $choix = $service->statutsChoisissablesParAdmin($this->commande($statut));
            $this->assertNotContains(CommandeStatut::REMBOURSEE, $choix, $statut->value);
            $this->assertNotContains(CommandeStatut::A_VERIFIER, $choix, $statut->value);
        }
        // Mais le webhook / le rattrapage, eux, le peuvent toujours (workflow)
        $this->assertTrue($service->peutAller($this->commande(CommandeStatut::LIVREE), CommandeStatut::LIVREE, CommandeStatut::REMBOURSEE));
    }

    public function testDepuisAVerifierLAdminPeutValiderOuAnnulerMaisPasRembourser(): void
    {
        $choix = $this->changementStatut()->statutsChoisissablesParAdmin($this->commande(CommandeStatut::A_VERIFIER));

        $this->assertContains(CommandeStatut::PAYEE, $choix);
        $this->assertContains(CommandeStatut::ANNULEE, $choix);
        $this->assertNotContains(CommandeStatut::REMBOURSEE, $choix);
    }

    public function testNoteObligatoirePourRetourneeEtPourValiderUnPaiementAVerifier(): void
    {
        $service = $this->changementStatut();

        $this->assertStringContainsString('motif du retour', (string) $service->noteObligatoire(CommandeStatut::EXPEDIEE, CommandeStatut::RETOURNEE));
        $this->assertStringContainsString('vérifié', (string) $service->noteObligatoire(CommandeStatut::A_VERIFIER, CommandeStatut::PAYEE));
        $this->assertNull($service->noteObligatoire(CommandeStatut::PAYEE, CommandeStatut::CONFIRMEE));
        $this->assertNull($service->noteObligatoire(CommandeStatut::EN_ATTENTE, CommandeStatut::PAYEE), 'Réservé au système : pas de note à demander');
        $this->assertNull($service->noteObligatoire(CommandeStatut::A_VERIFIER, CommandeStatut::ANNULEE));
    }
}