<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250918000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Complete database schema - consolidated migration';
    }

    public function up(Schema $schema): void
    {
        // Organisation table
        $this->addSql('CREATE TABLE IF NOT EXISTS organisation (
            id INT AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:guid)\',
            name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_E6E132B4D17F50A6 (uuid),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // User table
        $this->addSql('CREATE TABLE IF NOT EXISTS `user` (
            id INT AUTO_INCREMENT NOT NULL,
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
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Integration table (with organisation_id)
        $this->addSql('CREATE TABLE IF NOT EXISTS integration (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            organisation_id INT DEFAULT NULL,
            type VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            config JSON NOT NULL,
            encrypted_credentials LONGTEXT NOT NULL,
            active TINYINT(1) NOT NULL,
            last_accessed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_7A997E5FA76ED395 (user_id),
            INDEX IDX_7A997E5F9E6B1585 (organisation_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // IntegrationFunction table
        $this->addSql('CREATE TABLE IF NOT EXISTS integration_function (
            id INT AUTO_INCREMENT NOT NULL,
            integration_id INT NOT NULL,
            function_name VARCHAR(100) NOT NULL,
            description LONGTEXT NOT NULL,
            active TINYINT(1) NOT NULL,
            INDEX IDX_BF86BA469E82DDEA (integration_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // AuditLog table
        $this->addSql('CREATE TABLE IF NOT EXISTS audit_log (
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

        // UserOrganisation join table (with id and workflow_user_id)
        $this->addSql('CREATE TABLE IF NOT EXISTS user_organisation (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            organisation_id INT NOT NULL,
            role VARCHAR(50) DEFAULT "MEMBER",
            workflow_user_id VARCHAR(255) DEFAULT NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE INDEX unique_user_org (user_id, organisation_id),
            INDEX IDX_USER_ORG_USER (user_id),
            INDEX IDX_USER_ORG_ORG (organisation_id),
            INDEX IDX_USER_ORG_WORKFLOW (workflow_user_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Waitlist entries table
        $this->addSql('CREATE TABLE IF NOT EXISTS waitlist_entries (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_WAITLIST_EMAIL (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Foreign keys - only add if they don't exist
        $this->addSql('SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_NAME = "FK_7A997E5FA76ED395"
            AND TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "integration")');
        $this->addSql('SET @sql = IF(@fk_exists = 0,
            "ALTER TABLE integration ADD CONSTRAINT FK_7A997E5FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
            "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');

        $this->addSql('SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_NAME = "FK_7A997E5F9E6B1585"
            AND TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "integration")');
        $this->addSql('SET @sql = IF(@fk_exists = 0,
            "ALTER TABLE integration ADD CONSTRAINT FK_7A997E5F9E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)",
            "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');

        $this->addSql('SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_NAME = "FK_BF86BA469E82DDEA"
            AND TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "integration_function")');
        $this->addSql('SET @sql = IF(@fk_exists = 0,
            "ALTER TABLE integration_function ADD CONSTRAINT FK_BF86BA469E82DDEA FOREIGN KEY (integration_id) REFERENCES integration (id) ON DELETE CASCADE",
            "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');

        $this->addSql('SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_NAME = "FK_F6E1C0F59E6B1585"
            AND TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "audit_log")');
        $this->addSql('SET @sql = IF(@fk_exists = 0,
            "ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F59E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)",
            "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');

        $this->addSql('SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_NAME = "FK_F6E1C0F5A76ED395"
            AND TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "audit_log")');
        $this->addSql('SET @sql = IF(@fk_exists = 0,
            "ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)",
            "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');

        $this->addSql('SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_NAME = "FK_USER_ORG_USER"
            AND TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "user_organisation")');
        $this->addSql('SET @sql = IF(@fk_exists = 0,
            "ALTER TABLE user_organisation ADD CONSTRAINT FK_USER_ORG_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
            "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');

        $this->addSql('SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_NAME = "FK_USER_ORG_ORG"
            AND TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "user_organisation")');
        $this->addSql('SET @sql = IF(@fk_exists = 0,
            "ALTER TABLE user_organisation ADD CONSTRAINT FK_USER_ORG_ORG FOREIGN KEY (organisation_id) REFERENCES organisation (id) ON DELETE CASCADE",
            "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE user_organisation DROP FOREIGN KEY IF EXISTS FK_USER_ORG_USER');
        $this->addSql('ALTER TABLE user_organisation DROP FOREIGN KEY IF EXISTS FK_USER_ORG_ORG');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY IF EXISTS FK_F6E1C0F5A76ED395');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY IF EXISTS FK_F6E1C0F59E6B1585');
        $this->addSql('ALTER TABLE integration_function DROP FOREIGN KEY IF EXISTS FK_BF86BA469E82DDEA');
        $this->addSql('ALTER TABLE integration DROP FOREIGN KEY IF EXISTS FK_7A997E5FA76ED395');
        $this->addSql('ALTER TABLE integration DROP FOREIGN KEY IF EXISTS FK_7A997E5F9E6B1585');

        // Drop tables
        $this->addSql('DROP TABLE IF EXISTS waitlist_entries');
        $this->addSql('DROP TABLE IF EXISTS user_organisation');
        $this->addSql('DROP TABLE IF EXISTS audit_log');
        $this->addSql('DROP TABLE IF EXISTS integration_function');
        $this->addSql('DROP TABLE IF EXISTS integration');
        $this->addSql('DROP TABLE IF EXISTS `user`');
        $this->addSql('DROP TABLE IF EXISTS organisation');
    }
}