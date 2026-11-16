<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111223004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adresse de livraison COPIEE dans la commande (colonnes livraison_*), frais_livraison, telephone sur adresse, lien commande->adresse en ON DELETE SET NULL : ajout de colonnes et changement de contrainte, aucune donnee modifiee';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE adresse ADD telephone VARCHAR(20) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67DBE2F0A35
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD livraison_destinataire VARCHAR(150) DEFAULT NULL, ADD livraison_rue VARCHAR(255) DEFAULT NULL, ADD livraison_complement VARCHAR(255) DEFAULT NULL, ADD livraison_code_postal VARCHAR(10) DEFAULT NULL, ADD livraison_ville VARCHAR(100) DEFAULT NULL, ADD livraison_pays VARCHAR(100) DEFAULT NULL, ADD livraison_telephone VARCHAR(20) DEFAULT NULL, ADD frais_livraison DOUBLE PRECISION DEFAULT '0' NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67DBE2F0A35 FOREIGN KEY (adresse_livraison_id) REFERENCES adresse (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE adresse DROP telephone
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67DBE2F0A35
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP livraison_destinataire, DROP livraison_rue, DROP livraison_complement, DROP livraison_code_postal, DROP livraison_ville, DROP livraison_pays, DROP livraison_telephone, DROP frais_livraison
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67DBE2F0A35 FOREIGN KEY (adresse_livraison_id) REFERENCES adresse (id) ON UPDATE NO ACTION ON DELETE NO ACTION
        SQL);
    }
}
