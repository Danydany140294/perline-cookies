<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SUPPRIME DES DONNEES : colonnes inutilisees box.stock et produit.disponible. A jouer SEULEMENT apres une sauvegarde (mysqldump)
 * et sur decision du proprietaire (voir docs/colonnes-inutilisees.md).
 */
final class Version20261117230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression des colonnes inutilisees box.stock et produit.disponible (destructif : sauvegarde obligatoire)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE box DROP COLUMN stock');
        $this->addSql('ALTER TABLE produit DROP COLUMN disponible');
    }

    public function down(Schema $schema): void
    {
        // Les colonnes sont recreees avec leur valeur par defaut ; les valeurs d'origine ne sont pas restaurees (restaurer la sauvegarde).
        $this->addSql('ALTER TABLE box ADD stock INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE produit ADD disponible TINYINT(1) DEFAULT 1 NOT NULL');
    }
}
