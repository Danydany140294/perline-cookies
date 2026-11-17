<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116235635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte : e-mail confirme (valeur par defaut 1 : les comptes existants restent connectables) et adresse e-mail en attente de confirmation (ajout de deux colonnes, aucune donnee modifiee)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE user ADD email_verifie TINYINT(1) DEFAULT 1 NOT NULL, ADD email_en_attente VARCHAR(180) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE `user` DROP email_verifie, DROP email_en_attente
        SQL);
    }
}
