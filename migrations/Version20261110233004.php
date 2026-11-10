<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110233004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recette des boxes classiques : box_produit (sans quantite) remplacee par box_composition (avec quantite, 1 par defaut), liaisons conservees';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE box_composition (id INT AUTO_INCREMENT NOT NULL, box_id INT NOT NULL, produit_id INT NOT NULL, quantite INT DEFAULT 1 NOT NULL, INDEX IDX_2EC25475D8177B3F (box_id), INDEX IDX_2EC25475F347EFB (produit_id), UNIQUE INDEX uniq_box_composition_box_produit (box_id, produit_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_composition ADD CONSTRAINT FK_2EC25475D8177B3F FOREIGN KEY (box_id) REFERENCES box (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_composition ADD CONSTRAINT FK_2EC25475F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE CASCADE
        SQL);
        // Les liaisons existantes sont conservees, avec une quantite de 1 par defaut
        $this->addSql(<<<'SQL'
            INSERT INTO box_composition (box_id, produit_id, quantite) SELECT box_id, produit_id, 1 FROM box_produit
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_produit DROP FOREIGN KEY FK_30677216F347EFB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_produit DROP FOREIGN KEY FK_30677216D8177B3F
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE box_produit
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE box_produit (box_id INT NOT NULL, produit_id INT NOT NULL, INDEX IDX_30677216D8177B3F (box_id), INDEX IDX_30677216F347EFB (produit_id), PRIMARY KEY(box_id, produit_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = '' 
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_produit ADD CONSTRAINT FK_30677216F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON UPDATE NO ACTION ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_produit ADD CONSTRAINT FK_30677216D8177B3F FOREIGN KEY (box_id) REFERENCES box (id) ON UPDATE NO ACTION ON DELETE CASCADE
        SQL);
        // Retour arriere : les quantites sont perdues, les liaisons box/cookie sont conservees
        $this->addSql(<<<'SQL'
            INSERT INTO box_produit (box_id, produit_id) SELECT box_id, produit_id FROM box_composition
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_composition DROP FOREIGN KEY FK_2EC25475D8177B3F
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE box_composition DROP FOREIGN KEY FK_2EC25475F347EFB
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE box_composition
        SQL);
    }
}
