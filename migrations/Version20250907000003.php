<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250907000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_organisation join table for many-to-many relationship and remove direct organisation_id from user table';
    }

    public function up(Schema $schema): void
    {
        // Create join table for many-to-many relationship
        $this->addSql('CREATE TABLE IF NOT EXISTS user_organisation (
            user_id INT NOT NULL,
            organisation_id INT NOT NULL,
            role VARCHAR(50) DEFAULT "MEMBER",
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id, organisation_id),
            INDEX IDX_USER_ORG_USER (user_id),
            INDEX IDX_USER_ORG_ORG (organisation_id),
            CONSTRAINT FK_USER_ORG_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_USER_ORG_ORG FOREIGN KEY (organisation_id) REFERENCES organisation (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Migrate existing user-organisation relationships to the new table
        $this->addSql('INSERT INTO user_organisation (user_id, organisation_id, role, joined_at)
            SELECT id, organisation_id, "MEMBER", created_at 
            FROM `user` 
            WHERE organisation_id IS NOT NULL');

        // Remove the direct organisation_id from user table
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D6499E6B1585');
        $this->addSql('DROP INDEX IDX_8D93D6499E6B1585 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP organisation_id');
    }

    public function down(Schema $schema): void
    {
        // Add back the organisation_id column to user table
        $this->addSql('ALTER TABLE `user` ADD organisation_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8D93D6499E6B1585 ON `user` (organisation_id)');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D6499E6B1585 FOREIGN KEY (organisation_id) REFERENCES organisation (id)');
        
        // Migrate back the first organisation for each user (data loss possible)
        $this->addSql('UPDATE `user` u 
            SET organisation_id = (
                SELECT organisation_id 
                FROM user_organisation uo 
                WHERE uo.user_id = u.id 
                ORDER BY uo.joined_at ASC 
                LIMIT 1
            )');

        // Drop the join table
        $this->addSql('DROP TABLE user_organisation');
    }
}