<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250702000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial database schema';
    }

    public function up(Schema $schema): void
    {
        // Organisation table
        $this->addSql('CREATE TABLE organisation (
            id INT AUTO_INCREMENT NOT NULL, 
            uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:guid)\', 
            name VARCHAR(255) NOT NULL, 
            created_at DATETIME NOT NULL, 
            updated_at DATETIME NOT NULL, 
            UNIQUE INDEX UNIQ_E6E132B4D17F50A6 (uuid), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // User table
        $this->addSql('CREATE TABLE `user` (
            id INT AUTO_INCREMENT NOT NULL, 
            organisation_id INT DEFAULT NULL, 
            email VARCHAR(180) NOT NULL, 
            roles JSON NOT NULL, 
            name VARCHAR(255) NOT NULL, 
            google_id VARCHAR(255) DEFAULT NULL, 
            access_token LONGTEXT DEFAULT NULL, 
            refresh_token LONGTEXT DEFAULT NULL,
            token_expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL, 
            updated_at DATETIME NOT NULL, 
            UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), 
            INDEX IDX_8D93D6499E6B1585 (organisation_id), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Integration table
        $this->addSql('CREATE TABLE integration (
            id INT AUTO_INCREMENT NOT NULL, 
            user_id INT NOT NULL, 
            type VARCHAR(50) NOT NULL, 
            name VARCHAR(255) NOT NULL, 
            config JSON NOT NULL, 
            encrypted_credentials LONGTEXT NOT NULL, 
            active TINYINT(1) NOT NULL, 
            workflow_user_id VARCHAR(255) DEFAULT NULL,
            last_accessed_at DATETIME DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            updated_at DATETIME NOT NULL, 
            INDEX IDX_7A997E5FA76ED395 (user_id), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // IntegrationFunction table
        $this->addSql('CREATE TABLE integration_function (
            id INT AUTO_INCREMENT NOT NULL, 
            integration_id INT NOT NULL, 
            function_name VARCHAR(100) NOT NULL, 
            description LONGTEXT NOT NULL, 
            active TINYINT(1) NOT NULL, 
            INDEX IDX_BF86BA469E82DDEA (integration_id), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // AuditLog table
        $this->addSql('CREATE TABLE audit_log (
            id INT AUTO_INCREMENT NOT NULL, 
            organisation_id INT DEFAULT NULL, 
            user_id INT DEFAULT NULL, 
            action VARCHAR(255) NOT NULL, 
            data JSON DEFAULT NULL, 
            ip VARCHAR(45) DEFAULT NULL, 
            user_agent LONGTEXT DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            INDEX IDX_F6E1C0F59E6B1585 (organisation_id), 
            INDEX IDX_F6E1C0F5A76ED395 (user_id), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Foreign keys
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D6499E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        $this->addSql('ALTER TABLE integration ADD CONSTRAINT FK_7A997E5FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE integration_function ADD CONSTRAINT FK_BF86BA469E82DDEA FOREIGN KEY (integration_id) REFERENCES integration (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F59E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F5A76ED395');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F59E6B1585');
        $this->addSql('ALTER TABLE integration_function DROP FOREIGN KEY FK_BF86BA469E82DDEA');
        $this->addSql('ALTER TABLE integration DROP FOREIGN KEY FK_7A997E5FA76ED395');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D6499E6B1585');
        
        // Drop tables
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE integration_function');
        $this->addSql('DROP TABLE integration');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE organisation');
    }
}