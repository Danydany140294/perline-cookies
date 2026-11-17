<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117093632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Parametres de la boutique modifiables depuis administration (table parametre_boutique)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE parametre_boutique (cle VARCHAR(100) NOT NULL, valeur LONGTEXT NOT NULL, modifie_le DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(cle)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP TABLE parametre_boutique
        SQL);
    }
}
