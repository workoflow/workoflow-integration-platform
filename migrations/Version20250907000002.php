<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250907000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add organisation_id column to integration table with foreign key to organisation';
    }

    public function up(Schema $schema): void
    {
        // Add organisation_id column to integration table if it doesn't exist
        $this->addSql('ALTER TABLE integration ADD COLUMN IF NOT EXISTS organisation_id INT DEFAULT NULL');

        // Add index for organisation_id if it doesn't exist
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_7A997E5F9E6B1585 ON integration (organisation_id)');

        // Add foreign key constraint to organisation table if it doesn't exist
        $this->addSql('ALTER TABLE integration ADD CONSTRAINT IF NOT EXISTS FK_7A997E5F9E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE integration DROP FOREIGN KEY FK_7A997E5F9E6B1585');
        
        // Drop index
        $this->addSql('DROP INDEX IDX_7A997E5F9E6B1585 ON integration');
        
        // Drop column
        $this->addSql('ALTER TABLE integration DROP organisation_id');
    }
}