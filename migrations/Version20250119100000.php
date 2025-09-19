<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create integration_config table to replace integration and integration_function tables';
    }

    public function up(Schema $schema): void
    {
        // Create integration_config table with unique constraint
        $this->addSql('CREATE TABLE IF NOT EXISTS integration_config (
            id INT AUTO_INCREMENT NOT NULL,
            organisation_id INT NOT NULL,
            integration_type VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            workflow_user_id VARCHAR(255) DEFAULT NULL,
            encrypted_credentials LONGTEXT DEFAULT NULL,
            disabled_tools JSON NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            last_accessed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_integration_config_organisation (organisation_id),
            INDEX IDX_integration_config_type (integration_type),
            UNIQUE INDEX UNIQ_integration_config_org_type_name (organisation_id, integration_type, name),
            CONSTRAINT FK_integration_config_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // Migrate existing data from integration and integration_function tables if they exist
        $this->addSql("
            INSERT IGNORE INTO integration_config (
                organisation_id,
                integration_type,
                name,
                workflow_user_id,
                encrypted_credentials,
                disabled_tools,
                active,
                last_accessed_at,
                created_at,
                updated_at
            )
            SELECT
                i.organisation_id,
                i.type,
                CONCAT(UPPER(SUBSTRING(i.type, 1, 1)), SUBSTRING(i.type, 2), ' Instance'),
                CASE
                    WHEN JSON_EXTRACT(i.config, '$.workflow_user_id') IS NOT NULL
                    THEN JSON_UNQUOTE(JSON_EXTRACT(i.config, '$.workflow_user_id'))
                    ELSE NULL
                END,
                i.encrypted_credentials,
                COALESCE(
                    (SELECT JSON_ARRAYAGG(f.function_name)
                     FROM integration_function f
                     WHERE f.integration_id = i.id AND f.active = 0),
                    JSON_ARRAY()
                ),
                i.active,
                i.last_accessed_at,
                i.created_at,
                i.updated_at
            FROM integration i
            WHERE i.organisation_id IS NOT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS integration_config');
    }
}