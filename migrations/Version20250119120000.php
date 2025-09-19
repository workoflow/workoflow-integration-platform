<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add user_id field to integration_config table to track the owner
 */
final class Version20250119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_id field to integration_config table to track the owner';
    }

    public function up(Schema $schema): void
    {
        // Add user_id column to integration_config table
        $this->addSql('ALTER TABLE integration_config ADD COLUMN user_id INT DEFAULT NULL AFTER organisation_id');

        // Add foreign key constraint
        $this->addSql('ALTER TABLE integration_config ADD CONSTRAINT FK_INTEGRATION_CONFIG_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');

        // Add index for performance
        $this->addSql('CREATE INDEX IDX_INTEGRATION_CONFIG_USER ON integration_config (user_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE integration_config DROP FOREIGN KEY FK_INTEGRATION_CONFIG_USER');

        // Drop index
        $this->addSql('DROP INDEX IDX_INTEGRATION_CONFIG_USER ON integration_config');

        // Drop column
        $this->addSql('ALTER TABLE integration_config DROP COLUMN user_id');
    }
}