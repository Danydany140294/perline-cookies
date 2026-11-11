<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111210254 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historique des changements de statut des commandes (table commande_historique) : ajout seulement';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE commande_historique (id INT AUTO_INCREMENT NOT NULL, commande_id INT NOT NULL, ancien_statut VARCHAR(50) DEFAULT NULL, nouveau_statut VARCHAR(50) NOT NULL, date DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', source VARCHAR(30) NOT NULL, auteur VARCHAR(180) DEFAULT NULL, note LONGTEXT DEFAULT NULL, INDEX IDX_757DF90A82EA2E54 (commande_id), INDEX idx_historique_commande_date (commande_id, date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande_historique ADD CONSTRAINT FK_757DF90A82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE commande_historique DROP FOREIGN KEY FK_757DF90A82EA2E54
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE commande_historique
        SQL);
    }
}
