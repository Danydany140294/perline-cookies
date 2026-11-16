<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111231421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commande : periode de livraison annoncee au client (livraison_estimee_debut / fin) : ajout de deux colonnes, aucune donnee modifiee';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD livraison_estimee_debut DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', ADD livraison_estimee_fin DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP livraison_estimee_debut, DROP livraison_estimee_fin
        SQL);
    }
}
