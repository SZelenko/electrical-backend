<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127221729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Busbar colour: nullable busbar.colour with a CHECK on the wire colour set.';
    }

    public function up(Schema $schema): void
    {
        $colours = "'black', 'brown', 'red', 'blue', 'green-yellow'";

        $this->addSql('ALTER TABLE busbar ADD colour VARCHAR(12) DEFAULT NULL');
        $this->addSql("ALTER TABLE busbar ADD CONSTRAINT chk_busbar__colour CHECK (colour IS NULL OR colour IN ($colours))");

        // stale open editors get 409 Conflict
        $this->addSql('UPDATE panel SET version = version + 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE busbar DROP CONSTRAINT chk_busbar__colour');
        $this->addSql('ALTER TABLE busbar DROP colour');
    }
}
