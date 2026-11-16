<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111233057 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commande : transporteur, numero de suivi, date envoi e-mail expedition (ajout de trois colonnes nullables, aucune donnee modifiee)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD transporteur VARCHAR(50) DEFAULT NULL, ADD numero_suivi VARCHAR(30) DEFAULT NULL, ADD email_expedition_envoye_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP transporteur, DROP numero_suivi, DROP email_expedition_envoye_le
        SQL);
    }
}


