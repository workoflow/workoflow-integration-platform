<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251009095136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, organisation_id INT DEFAULT NULL, user_id INT DEFAULT NULL, action VARCHAR(255) NOT NULL, data JSON DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_F6E1C0F59E6B1585 (organisation_id), INDEX IDX_F6E1C0F5A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE integration_config (id INT AUTO_INCREMENT NOT NULL, organisation_id INT NOT NULL, user_id INT DEFAULT NULL, integration_type VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, encrypted_credentials LONGTEXT DEFAULT NULL, disabled_tools JSON NOT NULL, active TINYINT(1) NOT NULL, last_accessed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_25D49D699E6B1585 (organisation_id), INDEX IDX_25D49D69A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE organisation (id INT AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:guid)\', name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_E6E132B4D17F50A6 (uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, name VARCHAR(255) NOT NULL, google_id VARCHAR(255) DEFAULT NULL, access_token LONGTEXT DEFAULT NULL, refresh_token LONGTEXT DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_organisation (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, organisation_id INT NOT NULL, role VARCHAR(50) NOT NULL, workflow_user_id VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL, INDEX IDX_662D4EB6A76ED395 (user_id), INDEX IDX_662D4EB69E6B1585 (organisation_id), UNIQUE INDEX UNIQ_662D4EB6A76ED3959E6B1585 (user_id, organisation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE waitlist_entries (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(255) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_E74550EEE7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F59E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE integration_config ADD CONSTRAINT FK_25D49D699E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        $this->addSql('ALTER TABLE integration_config ADD CONSTRAINT FK_25D49D69A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE user_organisation ADD CONSTRAINT FK_662D4EB6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE user_organisation ADD CONSTRAINT FK_662D4EB69E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F59E6B1585');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F5A76ED395');
        $this->addSql('ALTER TABLE integration_config DROP FOREIGN KEY FK_25D49D699E6B1585');
        $this->addSql('ALTER TABLE integration_config DROP FOREIGN KEY FK_25D49D69A76ED395');
        $this->addSql('ALTER TABLE user_organisation DROP FOREIGN KEY FK_662D4EB6A76ED395');
        $this->addSql('ALTER TABLE user_organisation DROP FOREIGN KEY FK_662D4EB69E6B1585');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE integration_config');
        $this->addSql('DROP TABLE organisation');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE user_organisation');
        $this->addSql('DROP TABLE waitlist_entries');
    }
}
