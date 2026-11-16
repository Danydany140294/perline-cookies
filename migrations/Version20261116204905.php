<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116204905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TVA : taux par produit et par box (defaut 5,50 %), taux fige sur les lignes de commande, HT / TVA / detail par taux sur la commande (ajout de colonnes, aucune donnee existante modifiee hormis la valeur par defaut 5,50 sur les produits et boxes existants)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE box ADD taux_tva NUMERIC(4, 2) DEFAULT '5.50' NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD total_ht NUMERIC(10, 2) DEFAULT NULL, ADD total_tva NUMERIC(10, 2) DEFAULT NULL, ADD tva_detail JSON DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ligne_commande ADD taux_tva NUMERIC(4, 2) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE produit ADD taux_tva NUMERIC(4, 2) DEFAULT '5.50' NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE box DROP taux_tva
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP total_ht, DROP total_tva, DROP tva_detail
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ligne_commande DROP taux_tva
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE produit DROP taux_tva
        SQL);
    }
}
