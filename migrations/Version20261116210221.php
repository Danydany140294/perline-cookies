<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116210221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Factures et avoirs : tables facture et facture_compteur (creation de tables, aucune donnee existante modifiee)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE facture (id INT AUTO_INCREMENT NOT NULL, commande_id INT NOT NULL, facture_origine_id INT DEFAULT NULL, type VARCHAR(10) NOT NULL, numero VARCHAR(20) NOT NULL, annee SMALLINT NOT NULL, sequence INT NOT NULL, date_emission DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', total_ht NUMERIC(10, 2) NOT NULL, total_tva NUMERIC(10, 2) NOT NULL, total_ttc NUMERIC(10, 2) NOT NULL, tva_detail JSON NOT NULL, lignes JSON NOT NULL, emetteur JSON NOT NULL, client JSON NOT NULL, demo TINYINT(1) NOT NULL, chemin_pdf VARCHAR(255) DEFAULT NULL, empreinte VARCHAR(64) DEFAULT NULL, INDEX IDX_FE86641082EA2E54 (commande_id), INDEX IDX_FE866410EA98768A (facture_origine_id), UNIQUE INDEX uniq_facture_numero (numero), UNIQUE INDEX uniq_facture_sequence (type, annee, sequence), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE facture_compteur (type VARCHAR(10) NOT NULL, annee SMALLINT NOT NULL, derniere_sequence INT DEFAULT 0 NOT NULL, PRIMARY KEY(type, annee)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE facture ADD CONSTRAINT FK_FE86641082EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE facture ADD CONSTRAINT FK_FE866410EA98768A FOREIGN KEY (facture_origine_id) REFERENCES facture (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE facture DROP FOREIGN KEY FK_FE86641082EA2E54
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE facture DROP FOREIGN KEY FK_FE866410EA98768A
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE facture
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE facture_compteur
        SQL);
    }
}
