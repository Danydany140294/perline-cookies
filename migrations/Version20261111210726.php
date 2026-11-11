<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111210726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commande : identifiant de paiement Stripe, montant rembourse, stock retire / remis en vente (ajout de colonnes avec valeur par defaut, aucune donnee modifiee)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, ADD montant_rembourse DOUBLE PRECISION DEFAULT '0' NOT NULL, ADD stock_retire TINYINT(1) DEFAULT 0 NOT NULL, ADD stock_restitue TINYINT(1) DEFAULT 0 NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_commande_payment_intent ON commande (stripe_payment_intent_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP INDEX idx_commande_payment_intent ON commande
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP stripe_payment_intent_id, DROP montant_rembourse, DROP stock_retire, DROP stock_restitue
        SQL);
    }
}
