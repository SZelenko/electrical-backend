<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128113743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema + data migration: cable sides, top/bottom terminal rows, row offsets from the rail start, bends +40 mm.';
    }

    public function up(Schema $schema): void
    {
        // Accepted cosmetic cases: bends <= 45.0 mm on wires to old row contacts may leave a skewed first segment;
        // rails with first spacing <= ~36 mm may have terminals above 45 mm.

        $this->addSql("ALTER TABLE panel ADD input_cable_side VARCHAR(6) NOT NULL DEFAULT 'top'");
        $this->addSql("ALTER TABLE panel ADD output_cable_side VARCHAR(6) NOT NULL DEFAULT 'bottom'");
        $this->addSql('ALTER TABLE panel ALTER input_cable_side DROP DEFAULT');
        $this->addSql('ALTER TABLE panel ALTER output_cable_side DROP DEFAULT');
        $this->addSql("ALTER TABLE panel ADD CONSTRAINT chk_panel__input_cable_side CHECK (input_cable_side IN ('top', 'bottom', 'left', 'right'))");
        $this->addSql("ALTER TABLE panel ADD CONSTRAINT chk_panel__output_cable_side CHECK (output_cable_side IN ('top', 'bottom', 'left', 'right'))");

        $this->addSql('ALTER TABLE terminal_block ADD terminal_row VARCHAR(6) DEFAULT NULL');
        $this->addSql("UPDATE terminal_block SET terminal_row = 'top' WHERE rail_id IS NULL");
        $this->addSql("ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__row CHECK (terminal_row IS NULL OR terminal_row IN ('top', 'bottom'))");
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__row_placement CHECK ((rail_id IS NULL) = (terminal_row IS NOT NULL))');

        // row-block offsets: add the old row start (u = 0.1 mm; integer ceil via +174; ::int casts are required, SUM over bigint gives numeric)
        $this->addSql(<<<'SQL'
            WITH cw AS (
                SELECT c.panel_id, COUNT(w.id)::int AS wires
                FROM cable c LEFT JOIN cable_wire w ON w.cable_id = c.id
                WHERE c.kind = 'input'
                GROUP BY c.id, c.panel_id
            ), rs AS (
                SELECT panel_id, ((SUM(100 + 100 * wires) + 100 * (COUNT(*) - 1) + 100 + 174) / 175)::int AS row_start
                FROM cw GROUP BY panel_id
            )
            UPDATE terminal_block tb SET offset_modules = tb.offset_modules + rs.row_start
            FROM rs WHERE rs.panel_id = tb.panel_id AND tb.rail_id IS NULL
            SQL);

        // bends: rails and everything below move down by 40 mm
        $this->addSql('UPDATE panel_wire_bend SET y_mm = LEAST(y_mm + 40.0, 99999.9) WHERE y_mm > 45.0');

        // stale open editors get 409
        $this->addSql('UPDATE panel SET version = version + 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Row-block offsets are no longer bounded by the old row start, bends may sit in the 45-85 mm band and left-column shifts cannot be undone; restore from a backup if needed.');
    }
}
