<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127203316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Panels stage 4: element.style (breaker|rcd|voltageRelay for devices, NULL for ATS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE element ADD style VARCHAR(16) DEFAULT NULL');
        $this->addSql("UPDATE element SET style = 'breaker' WHERE kind = 'device' AND style IS NULL");
        $this->addSql("ALTER TABLE element ADD CONSTRAINT chk_element__style CHECK ((kind = 'device' AND style IN ('breaker', 'rcd', 'voltageRelay')) OR (kind = 'ats' AND style IS NULL))");
        $this->addSql('UPDATE panel SET version = version + 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE element DROP CONSTRAINT chk_element__style');
        $this->addSql('ALTER TABLE element DROP style');
    }
}
