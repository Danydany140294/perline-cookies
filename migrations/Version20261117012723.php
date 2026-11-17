<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117012723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alertes de stock : seuil par produit (facultatif) et drapeau alerte envoyee (ajout de deux colonnes, aucune donnee modifiee)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE produit ADD seuil_alerte INT DEFAULT NULL, ADD alerte_stock_envoyee TINYINT(1) DEFAULT 0 NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE produit DROP seuil_alerte, DROP alerte_stock_envoyee
        SQL);
    }
}
