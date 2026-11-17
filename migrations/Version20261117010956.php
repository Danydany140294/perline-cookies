<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117010956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal des actions administratives : table journal_admin (creation de table, aucune donnee existante modifiee)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE journal_admin (id INT AUTO_INCREMENT NOT NULL, date_action DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', auteur VARCHAR(180) NOT NULL, action VARCHAR(30) NOT NULL, entite VARCHAR(40) NOT NULL, entite_id INT DEFAULT NULL, libelle VARCHAR(255) NOT NULL, avant JSON DEFAULT NULL, apres JSON DEFAULT NULL, INDEX idx_journal_date (date_action), INDEX idx_journal_entite (entite, entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP TABLE journal_admin
        SQL);
    }
}
