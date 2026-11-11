<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « fix: missing foreign key constraints » : ajoute les clés étrangères.
 *
 * Ces 24 clés sont en réalité DÉJÀ créées par Version20260203193207 (23) et Version20260204193631 (1) :
 * rejouer cette migration à l'identique échouait (erreur 1826 « Duplicate foreign key constraint »)
 * sur toute base vierge. Elle est donc rendue idempotente :
 *  - up()   : n'ajoute une clé que si sa table existe encore et que la clé est absente ;
 *  - down() : ne supprime RIEN (les clés appartiennent aux migrations qui les créent).
 */
final class Version20260531223135 extends AbstractMigration
{
    /** [table, nom de la contrainte, définition] */
    private const CLES_ETRANGERES = [
            ['adresse', 'FK_C35F0816A76ED395', 'FOREIGN KEY (user_id) REFERENCES `user` (id)'],
            ['avis', 'FK_8F91ABF0A76ED395', 'FOREIGN KEY (user_id) REFERENCES `user` (id)'],
            ['avis', 'FK_8F91ABF0F347EFB', 'FOREIGN KEY (produit_id) REFERENCES produit (id)'],
            ['avis', 'FK_8F91ABF082EA2E54', 'FOREIGN KEY (commande_id) REFERENCES commande (id)'],
            ['box', 'FK_8A9483A73A201E5', 'FOREIGN KEY (createur_id) REFERENCES `user` (id) ON DELETE CASCADE'],
            ['box_produit', 'FK_30677216D8177B3F', 'FOREIGN KEY (box_id) REFERENCES box (id) ON DELETE CASCADE'],
            ['box_produit', 'FK_30677216F347EFB', 'FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE CASCADE'],
            ['commande', 'FK_6EEAA67DA76ED395', 'FOREIGN KEY (user_id) REFERENCES `user` (id)'],
            ['commande', 'FK_6EEAA67DBE2F0A35', 'FOREIGN KEY (adresse_livraison_id) REFERENCES adresse (id)'],
            ['composition_box_personnalisable', 'FK_1E78DBE9E10FEE63', 'FOREIGN KEY (ligne_commande_id) REFERENCES ligne_commande (id)'],
            ['composition_box_personnalisable', 'FK_1E78DBE9F347EFB', 'FOREIGN KEY (produit_id) REFERENCES produit (id)'],
            ['composition_panier_personnalisable', 'FK_B42582D838989DF4', 'FOREIGN KEY (ligne_panier_id) REFERENCES ligne_panier (id)'],
            ['composition_panier_personnalisable', 'FK_B42582D8F347EFB', 'FOREIGN KEY (produit_id) REFERENCES produit (id)'],
            ['ligne_commande', 'FK_3170B74B82EA2E54', 'FOREIGN KEY (commande_id) REFERENCES commande (id)'],
            ['ligne_commande', 'FK_3170B74BF347EFB', 'FOREIGN KEY (produit_id) REFERENCES produit (id)'],
            ['ligne_commande', 'FK_3170B74BD8177B3F', 'FOREIGN KEY (box_id) REFERENCES box (id)'],
            ['ligne_panier', 'FK_21691B4F77D927C', 'FOREIGN KEY (panier_id) REFERENCES panier (id)'],
            ['ligne_panier', 'FK_21691B4F347EFB', 'FOREIGN KEY (produit_id) REFERENCES produit (id)'],
            ['ligne_panier', 'FK_21691B4D8177B3F', 'FOREIGN KEY (box_id) REFERENCES box (id)'],
            ['moyen_paiement', 'FK_ED4417D2A76ED395', 'FOREIGN KEY (user_id) REFERENCES `user` (id)'],
            ['panier', 'FK_24CC0DF2A76ED395', 'FOREIGN KEY (user_id) REFERENCES `user` (id)'],
            ['reset_password_request', 'FK_7CE748AA76ED395', 'FOREIGN KEY (user_id) REFERENCES `user` (id)'],
            ['user_favoris', 'FK_D13EDA38A76ED395', 'FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE'],
            ['user_favoris', 'FK_D13EDA38F347EFB', 'FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE CASCADE'],
    ];

    public function getDescription(): string
    {
        return 'Clés étrangères : ajout seulement si absentes (idempotente), down() sans effet';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CLES_ETRANGERES as [$table, $nom, $definition]) {
            // Table disparue depuis (ex. box_produit, remplacée par box_composition) ou clé déjà présente : rien à faire.
            if (!$this->tableExiste($table) || $this->cleExiste($table, $nom)) {
                continue;
            }

            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s %s', $table, $nom, $definition));
        }
    }

    public function down(Schema $schema): void
    {
        // Volontairement vide : cette migration ne possède aucune clé étrangère (voir l'en-tête).
        $this->write('Version20260531223135 : down() sans effet (les clés appartiennent aux migrations qui les créent).');
    }

    private function tableExiste(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    private function cleExiste(string $table, string $nom): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$table, $nom]
        ) > 0;
    }
}