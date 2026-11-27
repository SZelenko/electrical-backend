<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127212411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Panels stage 5: cable label, panel-wide rail width, busbars, busbar wire endpoints, geometry shift of bends.';
    }

    public function up(Schema $schema): void
    {
        // 1 + (a) cable.label
        $this->addSql("ALTER TABLE cable ADD label VARCHAR(16) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE cable ALTER label DROP DEFAULT');

        // 2 + (b) panel.rail_width_modules = widest rail of the panel, 60 without rails
        $this->addSql('ALTER TABLE panel ADD rail_width_modules SMALLINT NOT NULL DEFAULT 60');
        $this->addSql('UPDATE panel p SET rail_width_modules = w.width FROM (SELECT panel_id, MAX(width_modules) AS width FROM din_rail GROUP BY panel_id) w WHERE w.panel_id = p.id');
        $this->addSql('ALTER TABLE panel ALTER rail_width_modules DROP DEFAULT');
        $this->addSql('ALTER TABLE panel ADD CONSTRAINT chk_panel__rail_width CHECK (rail_width_modules BETWEEN 1 AND 60)');

        // 3 + (c) the width is panel-wide now
        $this->addSql('ALTER TABLE din_rail DROP CONSTRAINT chk_din_rail__width');
        $this->addSql('ALTER TABLE din_rail DROP width_modules');

        // (d) delete row blocks that do not fit the new row (wires cascade by FK).
        // inputRowW = sum(100 + 100 * wires) + 100 * (inputCables - 1); rowStartModules = ceil((inputRowW + 100) / 175)
        $this->addSql(<<<'SQL'
            DELETE FROM terminal_block tb
            USING panel p, (
                SELECT c.panel_id, SUM(100 + 100 * COALESCE(n.wires, 0)) + 100 * (COUNT(*) - 1) AS row_w
                FROM cable c
                LEFT JOIN (SELECT cable_id, COUNT(*) AS wires FROM cable_wire GROUP BY cable_id) n ON n.cable_id = c.id
                WHERE c.kind = 'input'
                GROUP BY c.panel_id
            ) iw
            WHERE tb.rail_id IS NULL
              AND p.id = tb.panel_id
              AND iw.panel_id = tb.panel_id
              AND tb.offset_modules + tb.width_modules > p.rail_width_modules - CEIL((iw.row_w + 100) / 175.0)::int
            SQL);

        // (e) row-block wires get new geometry: drop their bends (they are re-routed by the editor)
        $this->addSql('DELETE FROM panel_wire_bend WHERE wire_id IN (SELECT w.id FROM panel_wire w JOIN terminal_block tb ON tb.id IN (w.start_terminal_block_id, w.end_terminal_block_id) WHERE tb.rail_id IS NULL)');

        // (f) the old band 45..100 mm (row + gap) compresses to 45..60 mm; below it is shifted up by 40 mm
        $this->addSql('UPDATE panel_wire_bend SET y_mm = CASE WHEN y_mm >= 100.0 THEN y_mm - 40.0 ELSE ROUND(45.0 + (y_mm - 45.0) * 15.0 / 55.0, 1) END WHERE y_mm > 45.0');

        // 4 + (g) busbar
        $this->addSql('CREATE TABLE busbar (id UUID NOT NULL, type VARCHAR(2) NOT NULL, device_count SMALLINT NOT NULL, first_element_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_busbar__first_element ON busbar (first_element_id)');
        $this->addSql('ALTER TABLE busbar ADD CONSTRAINT FK_35F83A878E874B23 FOREIGN KEY (first_element_id) REFERENCES element (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE busbar ADD CONSTRAINT chk_busbar__type CHECK (type IN ('L', 'LN'))");
        $this->addSql('ALTER TABLE busbar ADD CONSTRAINT chk_busbar__device_count CHECK (device_count BETWEEN 2 AND 60)');

        // 5 + (h) busbar wire endpoints
        $this->addSql('ALTER TABLE panel_wire ADD start_busbar_feed_index SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE panel_wire ADD end_busbar_feed_index SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE panel_wire ADD start_busbar_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE panel_wire ADD end_busbar_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D0DC3C1791 FOREIGN KEY (start_busbar_id) REFERENCES busbar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D08724D05F FOREIGN KEY (end_busbar_id) REFERENCES busbar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_167ED8D0DC3C1791 ON panel_wire (start_busbar_id)');
        $this->addSql('CREATE INDEX IDX_167ED8D08724D05F ON panel_wire (end_busbar_id)');
        foreach (['start', 'end'] as $end) {
            $this->addSql("ALTER TABLE panel_wire DROP CONSTRAINT chk_panel_wire__{$end}_endpoint");
            $this->addSql("ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__{$end}_endpoint CHECK (num_nonnulls({$end}_element_id, {$end}_terminal_block_id, {$end}_cable_wire_id, {$end}_busbar_id) = 1)");
            $this->addSql("ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__{$end}_busbar CHECK (({$end}_busbar_id IS NOT NULL) = ({$end}_busbar_feed_index IS NOT NULL) AND ({$end}_busbar_feed_index IS NULL OR {$end}_busbar_feed_index BETWEEN 0 AND 1))");
        }

        // (i) stale open editors get 409 Conflict
        $this->addSql('UPDATE panel SET version = version + 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Stage 5 merged per-rail widths into one panel width, deleted row blocks and wires, and rescaled bend coordinates; it cannot be reversed (dev data only, restore from a backup if needed).');
    }
}
