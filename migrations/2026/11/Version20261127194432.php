<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127194432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Panels stage 3: ATS elements, terminal-row blocks, block colour/label, wire contact sides.';
    }

    public function up(Schema $schema): void
    {
        $colours = "'black', 'brown', 'red', 'blue', 'green-yellow'";
        $sides = "'top', 'bottom', 'left', 'right'";

        // terminal_block: panel_id (nullable -> backfill from the rail -> NOT NULL + FK/index), colour, label
        $this->addSql('ALTER TABLE terminal_block ADD panel_id UUID DEFAULT NULL');
        $this->addSql('UPDATE terminal_block tb SET panel_id = r.panel_id FROM din_rail r WHERE r.id = tb.rail_id AND tb.panel_id IS NULL');
        $this->addSql('ALTER TABLE terminal_block ALTER panel_id SET NOT NULL');
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT FK_3370C1A56F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3370C1A56F6FCB26 ON terminal_block (panel_id)');
        $this->addSql('ALTER TABLE terminal_block ADD colour VARCHAR(12) DEFAULT NULL');
        $this->addSql("ALTER TABLE terminal_block ADD label VARCHAR(16) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE terminal_block ALTER label DROP DEFAULT');
        $this->addSql('ALTER TABLE terminal_block ALTER rail_id DROP NOT NULL');

        // existing blocks become 1-module rail blocks with at most 12 contacts
        $this->addSql('ALTER TABLE terminal_block DROP CONSTRAINT chk_terminal_block__terminal_count');
        $this->addSql('DELETE FROM panel_wire WHERE start_terminal_index >= 12 OR end_terminal_index >= 12');
        $this->addSql('UPDATE terminal_block SET width_modules = 1, terminal_count = LEAST(terminal_count, 12)');
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__shape CHECK ((rail_id IS NOT NULL AND width_modules = 1 AND terminal_count BETWEEN 1 AND 12) OR (rail_id IS NULL AND terminal_count BETWEEN 1 AND 60 AND width_modules = (terminal_count + 1) / 2))');
        $this->addSql("ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__colour CHECK (colour IS NULL OR colour IN ($colours))");

        // panel_wire: contact sides; existing rail-block ends are attached on the left
        $this->addSql('ALTER TABLE panel_wire ADD start_contact_side VARCHAR(6) DEFAULT NULL');
        $this->addSql('ALTER TABLE panel_wire ADD end_contact_side VARCHAR(6) DEFAULT NULL');
        $this->addSql("UPDATE panel_wire SET start_contact_side = 'left' WHERE start_terminal_block_id IS NOT NULL");
        $this->addSql("UPDATE panel_wire SET end_contact_side = 'left' WHERE end_terminal_block_id IS NOT NULL");
        $this->addSql('DELETE FROM panel_wire_bend WHERE wire_id IN (SELECT id FROM panel_wire WHERE start_terminal_block_id IS NOT NULL OR end_terminal_block_id IS NOT NULL)');
        foreach (['start', 'end'] as $end) {
            $this->addSql("ALTER TABLE panel_wire DROP CONSTRAINT chk_panel_wire__{$end}_terminal");
            $this->addSql("ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__{$end}_terminal CHECK (((({$end}_terminal_block_id IS NOT NULL) = ({$end}_terminal_index IS NOT NULL)) AND (({$end}_terminal_block_id IS NOT NULL) = ({$end}_contact_side IS NOT NULL))) AND ({$end}_terminal_index IS NULL OR {$end}_terminal_index BETWEEN 0 AND 59) AND ({$end}_contact_side IS NULL OR {$end}_contact_side IN ($sides)))");
        }

        // element: kind (device|ats), ats_state, is_on nullable
        $this->addSql("ALTER TABLE element ADD kind VARCHAR(6) NOT NULL DEFAULT 'device'");
        $this->addSql('ALTER TABLE element ALTER kind DROP DEFAULT');
        $this->addSql('ALTER TABLE element ADD ats_state VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE element ALTER is_on DROP NOT NULL');
        $this->addSql("ALTER TABLE element ADD CONSTRAINT chk_element__kind CHECK (kind IN ('device', 'ats'))");
        $this->addSql("ALTER TABLE element ADD CONSTRAINT chk_element__kind_fields CHECK ((kind = 'device' AND is_on IS NOT NULL AND ats_state IS NULL) OR (kind = 'ats' AND is_on IS NULL AND ats_state IN ('A', 'B', 'off') AND width_modules >= 2))");

        // drawing geometry: the terminal row shifts everything below it down by 40 mm
        $this->addSql('UPDATE panel_wire_bend SET y_mm = LEAST(y_mm + 40.0, 99999.9) WHERE y_mm > 45.0');

        // stale open editors get 409 Conflict
        $this->addSql('UPDATE panel SET version = version + 1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Stage 3 deleted wires/bends, narrowed terminal blocks and shifted bend coordinates; it cannot be reversed (dev data only, restore from a backup if needed).');
    }
}
