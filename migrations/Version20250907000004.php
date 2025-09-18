<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250907000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move workflow_user_id from integration table to user_organisation table';
    }

    public function up(Schema $schema): void
    {
        // Skip this migration if the table already has the expected structure
        // Check if workflow_user_id already exists in user_organisation
        $this->addSql("
            SET @has_workflow_id = (
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'user_organisation'
                AND COLUMN_NAME = 'workflow_user_id'
            )
        ");

        // Only run migration if workflow_user_id doesn't exist yet
        $this->addSql("
            SET @should_migrate = IF(@has_workflow_id = 0, 1, 0)
        ");

        // Add ID column if migration is needed
        $this->addSql("
            ALTER TABLE user_organisation
            ADD COLUMN IF NOT EXISTS id INT NOT NULL AUTO_INCREMENT FIRST
        ");

        // Add workflow_user_id column if it doesn't exist
        $this->addSql('ALTER TABLE user_organisation ADD COLUMN IF NOT EXISTS workflow_user_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_USER_ORG_WORKFLOW ON user_organisation (workflow_user_id)');
        $this->addSql('ALTER TABLE user_organisation ADD UNIQUE KEY IF NOT EXISTS unique_user_org (user_id, organisation_id)');

        // Migrate existing workflow_user_id data from integration to user_organisation if column exists in integration
        $this->addSql("
            SET @has_integration_workflow = (
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'integration'
                AND COLUMN_NAME = 'workflow_user_id'
            )
        ");

        // Only migrate if the column exists in integration table
        $this->addSql("
            UPDATE user_organisation uo
            INNER JOIN (
                SELECT DISTINCT i.user_id, i.organisation_id, i.workflow_user_id
                FROM integration i
                WHERE i.workflow_user_id IS NOT NULL
                  AND i.organisation_id IS NOT NULL
                  AND @has_integration_workflow = 1
            ) AS integration_data
            ON uo.user_id = integration_data.user_id
            AND uo.organisation_id = integration_data.organisation_id
            SET uo.workflow_user_id = integration_data.workflow_user_id
            WHERE @has_integration_workflow = 1
        ");

        // Remove workflow_user_id column from integration table if it exists
        $this->addSql('ALTER TABLE integration DROP COLUMN IF EXISTS workflow_user_id');
    }

    public function down(Schema $schema): void
    {
        // Add workflow_user_id column back to integration table
        $this->addSql('ALTER TABLE integration ADD workflow_user_id VARCHAR(255) DEFAULT NULL');

        // Migrate data back from user_organisation to integration
        // Note: This will duplicate the workflow_user_id across all integrations for the same user-org pair
        $this->addSql('
            UPDATE integration i
            INNER JOIN user_organisation uo
            ON i.user_id = uo.user_id
            AND i.organisation_id = uo.organisation_id
            SET i.workflow_user_id = uo.workflow_user_id
            WHERE uo.workflow_user_id IS NOT NULL
        ');

        // Remove workflow_user_id column from user_organisation table
        $this->addSql('DROP INDEX IDX_USER_ORG_WORKFLOW ON user_organisation');
        $this->addSql('ALTER TABLE user_organisation DROP COLUMN workflow_user_id');

        // Restore original primary key structure
        $this->addSql('ALTER TABLE user_organisation DROP INDEX unique_user_org');
        $this->addSql('ALTER TABLE user_organisation DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE user_organisation DROP COLUMN id');
        $this->addSql('ALTER TABLE user_organisation ADD PRIMARY KEY (user_id, organisation_id)');
    }
}