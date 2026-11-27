<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127140451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Panels stage 1: panel, cable, cable_wire, din_rail, element, element_pole, terminal_block, panel_wire, panel_wire_bend with CHECK constraints.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cable (id UUID NOT NULL, kind VARCHAR(6) NOT NULL, position SMALLINT NOT NULL, voltage_v NUMERIC(6, 1) DEFAULT NULL, frequency_hz NUMERIC(5, 2) DEFAULT NULL, cross_section_mm2 NUMERIC(5, 2) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, panel_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cable__panel_kind_position ON cable (panel_id, kind, position)');
        $this->addSql('CREATE INDEX IDX_24E9C4D46F6FCB26 ON cable (panel_id)');
        $this->addSql('CREATE TABLE cable_wire (id UUID NOT NULL, position SMALLINT NOT NULL, colour VARCHAR(12) NOT NULL, cable_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cable_wire__cable_position ON cable_wire (cable_id, position)');
        $this->addSql('CREATE INDEX IDX_3FDFE36F9A81106E ON cable_wire (cable_id)');
        $this->addSql('CREATE TABLE din_rail (id UUID NOT NULL, position SMALLINT NOT NULL, width_modules SMALLINT NOT NULL, spacing_mm NUMERIC(6, 1) NOT NULL, panel_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_din_rail__panel_position ON din_rail (panel_id, position)');
        $this->addSql('CREATE INDEX IDX_DF087AB36F6FCB26 ON din_rail (panel_id)');
        $this->addSql('CREATE TABLE element (id UUID NOT NULL, offset_modules SMALLINT NOT NULL, width_modules SMALLINT NOT NULL, label VARCHAR(16) NOT NULL, marking VARCHAR(6) NOT NULL, is_on BOOLEAN NOT NULL, sides_swapped BOOLEAN NOT NULL, rail_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_41405E3959E18326 ON element (rail_id)');
        $this->addSql('CREATE TABLE element_pole (pole_index SMALLINT NOT NULL, is_transit BOOLEAN NOT NULL, element_id UUID NOT NULL, PRIMARY KEY (element_id, pole_index))');
        $this->addSql('CREATE INDEX IDX_BE5E63181F1F2A24 ON element_pole (element_id)');
        $this->addSql('CREATE TABLE panel (id UUID NOT NULL, name VARCHAR(100) NOT NULL, name_key VARCHAR(255) NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_panel__owner_deleted_updated ON panel (owner_id, deleted_at, updated_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_panel__owner_name_key ON panel (owner_id, name_key) WHERE (deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_A2ADD30F7E3C61F9 ON panel (owner_id)');
        $this->addSql('CREATE TABLE panel_wire (id UUID NOT NULL, position INT NOT NULL, colour VARCHAR(12) NOT NULL, cross_section VARCHAR(3) NOT NULL, start_pole_index SMALLINT DEFAULT NULL, start_pole_side VARCHAR(6) DEFAULT NULL, start_terminal_index SMALLINT DEFAULT NULL, end_pole_index SMALLINT DEFAULT NULL, end_pole_side VARCHAR(6) DEFAULT NULL, end_terminal_index SMALLINT DEFAULT NULL, panel_id UUID NOT NULL, start_element_id UUID DEFAULT NULL, start_terminal_block_id UUID DEFAULT NULL, start_cable_wire_id UUID DEFAULT NULL, end_element_id UUID DEFAULT NULL, end_terminal_block_id UUID DEFAULT NULL, end_cable_wire_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_panel_wire__panel_position ON panel_wire (panel_id, position)');
        $this->addSql('CREATE INDEX IDX_167ED8D06F6FCB26 ON panel_wire (panel_id)');
        $this->addSql('CREATE INDEX IDX_167ED8D06DD085DA ON panel_wire (start_element_id)');
        $this->addSql('CREATE INDEX IDX_167ED8D04D6E3819 ON panel_wire (start_terminal_block_id)');
        $this->addSql('CREATE INDEX IDX_167ED8D0DBEB07D7 ON panel_wire (start_cable_wire_id)');
        $this->addSql('CREATE INDEX IDX_167ED8D0115772AA ON panel_wire (end_element_id)');
        $this->addSql('CREATE INDEX IDX_167ED8D08BBEB235 ON panel_wire (end_terminal_block_id)');
        $this->addSql('CREATE INDEX IDX_167ED8D0A73B0E02 ON panel_wire (end_cable_wire_id)');
        $this->addSql('CREATE TABLE panel_wire_bend (position SMALLINT NOT NULL, x_mm NUMERIC(7, 1) NOT NULL, y_mm NUMERIC(7, 1) NOT NULL, wire_id UUID NOT NULL, PRIMARY KEY (wire_id, position))');
        $this->addSql('CREATE INDEX IDX_3A11648E8DB2C7F5 ON panel_wire_bend (wire_id)');
        $this->addSql('CREATE TABLE terminal_block (id UUID NOT NULL, offset_modules SMALLINT NOT NULL, width_modules SMALLINT NOT NULL, terminal_count SMALLINT NOT NULL, rail_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3370C1A559E18326 ON terminal_block (rail_id)');
        $this->addSql('ALTER TABLE cable ADD CONSTRAINT FK_24E9C4D46F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cable_wire ADD CONSTRAINT FK_3FDFE36F9A81106E FOREIGN KEY (cable_id) REFERENCES cable (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE din_rail ADD CONSTRAINT FK_DF087AB36F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE element ADD CONSTRAINT FK_41405E3959E18326 FOREIGN KEY (rail_id) REFERENCES din_rail (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE element_pole ADD CONSTRAINT FK_BE5E63181F1F2A24 FOREIGN KEY (element_id) REFERENCES element (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel ADD CONSTRAINT FK_A2ADD30F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D06F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D06DD085DA FOREIGN KEY (start_element_id) REFERENCES element (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D04D6E3819 FOREIGN KEY (start_terminal_block_id) REFERENCES terminal_block (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D0DBEB07D7 FOREIGN KEY (start_cable_wire_id) REFERENCES cable_wire (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D0115772AA FOREIGN KEY (end_element_id) REFERENCES element (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D08BBEB235 FOREIGN KEY (end_terminal_block_id) REFERENCES terminal_block (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT FK_167ED8D0A73B0E02 FOREIGN KEY (end_cable_wire_id) REFERENCES cable_wire (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE panel_wire_bend ADD CONSTRAINT FK_3A11648E8DB2C7F5 FOREIGN KEY (wire_id) REFERENCES panel_wire (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT FK_3370C1A559E18326 FOREIGN KEY (rail_id) REFERENCES din_rail (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE panel ADD CONSTRAINT chk_panel__name_length CHECK (char_length(name) >= 1)');
        $this->addSql('ALTER TABLE panel ADD CONSTRAINT chk_panel__name_trimmed CHECK (name = btrim(name))');
        $this->addSql("ALTER TABLE panel ADD CONSTRAINT chk_panel__name_key CHECK (name_key <> '')");
        $this->addSql('ALTER TABLE panel ADD CONSTRAINT chk_panel__version CHECK (version >= 1)');
        $this->addSql('ALTER TABLE panel ADD CONSTRAINT chk_panel__timestamps CHECK (updated_at >= created_at AND (deleted_at IS NULL OR deleted_at >= created_at))');
        $this->addSql("ALTER TABLE cable ADD CONSTRAINT chk_cable__kind CHECK (kind IN ('input', 'output'))");
        $this->addSql('ALTER TABLE cable ADD CONSTRAINT chk_cable__position CHECK (position >= 0)');
        $this->addSql("ALTER TABLE cable ADD CONSTRAINT chk_cable__kind_fields CHECK ((kind = 'input' AND voltage_v IS NOT NULL AND frequency_hz IS NOT NULL AND cross_section_mm2 IS NOT NULL AND description IS NULL) OR (kind = 'output' AND voltage_v IS NULL AND frequency_hz IS NULL AND cross_section_mm2 IS NULL AND description IS NOT NULL))");
        $this->addSql('ALTER TABLE cable ADD CONSTRAINT chk_cable__voltage CHECK (voltage_v IS NULL OR (voltage_v > 0 AND voltage_v <= 99999.9))');
        $this->addSql('ALTER TABLE cable ADD CONSTRAINT chk_cable__frequency CHECK (frequency_hz IS NULL OR (frequency_hz >= 0 AND frequency_hz <= 999.99))');
        $this->addSql('ALTER TABLE cable ADD CONSTRAINT chk_cable__cross_section CHECK (cross_section_mm2 IS NULL OR (cross_section_mm2 > 0 AND cross_section_mm2 <= 999.99))');
        $this->addSql('ALTER TABLE cable_wire ADD CONSTRAINT chk_cable_wire__position CHECK (position BETWEEN 0 AND 3)');
        $this->addSql("ALTER TABLE cable_wire ADD CONSTRAINT chk_cable_wire__colour CHECK (colour IN ('black', 'brown', 'red', 'blue', 'green-yellow'))");
        $this->addSql('ALTER TABLE din_rail ADD CONSTRAINT chk_din_rail__position CHECK (position BETWEEN 0 AND 7)');
        $this->addSql('ALTER TABLE din_rail ADD CONSTRAINT chk_din_rail__width CHECK (width_modules BETWEEN 1 AND 60)');
        $this->addSql('ALTER TABLE din_rail ADD CONSTRAINT chk_din_rail__spacing CHECK (spacing_mm >= 0 AND spacing_mm <= 99999.9)');
        $this->addSql('ALTER TABLE element ADD CONSTRAINT chk_element__offset CHECK (offset_modules >= 0)');
        $this->addSql('ALTER TABLE element ADD CONSTRAINT chk_element__width CHECK (width_modules >= 1)');
        $this->addSql('ALTER TABLE element ADD CONSTRAINT chk_element__fit CHECK (offset_modules + width_modules <= 60)');
        $this->addSql('ALTER TABLE element_pole ADD CONSTRAINT chk_element_pole__pole_index CHECK (pole_index BETWEEN 0 AND 239)');
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__offset CHECK (offset_modules >= 0)');
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__width CHECK (width_modules >= 1)');
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__fit CHECK (offset_modules + width_modules <= 60)');
        $this->addSql('ALTER TABLE terminal_block ADD CONSTRAINT chk_terminal_block__terminal_count CHECK (terminal_count BETWEEN 1 AND 2 * width_modules)');
        $this->addSql("ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__colour CHECK (colour IN ('black', 'brown', 'red', 'blue', 'green-yellow'))");
        $this->addSql("ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__cross_section CHECK (cross_section IN ('1', '1.5', '2.5', '4', '6'))");
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__position CHECK (position >= 0)');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__start_endpoint CHECK (num_nonnulls(start_element_id, start_terminal_block_id, start_cable_wire_id) = 1)');
        $this->addSql("ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__start_pole CHECK (((start_element_id IS NOT NULL) = (start_pole_index IS NOT NULL)) AND ((start_element_id IS NOT NULL) = (start_pole_side IS NOT NULL)) AND (start_pole_index IS NULL OR start_pole_index BETWEEN 0 AND 239) AND (start_pole_side IS NULL OR start_pole_side IN ('input', 'output')))");
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__start_terminal CHECK (((start_terminal_block_id IS NOT NULL) = (start_terminal_index IS NOT NULL)) AND (start_terminal_index IS NULL OR start_terminal_index BETWEEN 0 AND 119))');
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__end_endpoint CHECK (num_nonnulls(end_element_id, end_terminal_block_id, end_cable_wire_id) = 1)');
        $this->addSql("ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__end_pole CHECK (((end_element_id IS NOT NULL) = (end_pole_index IS NOT NULL)) AND ((end_element_id IS NOT NULL) = (end_pole_side IS NOT NULL)) AND (end_pole_index IS NULL OR end_pole_index BETWEEN 0 AND 239) AND (end_pole_side IS NULL OR end_pole_side IN ('input', 'output')))");
        $this->addSql('ALTER TABLE panel_wire ADD CONSTRAINT chk_panel_wire__end_terminal CHECK (((end_terminal_block_id IS NOT NULL) = (end_terminal_index IS NOT NULL)) AND (end_terminal_index IS NULL OR end_terminal_index BETWEEN 0 AND 119))');
        $this->addSql('ALTER TABLE panel_wire_bend ADD CONSTRAINT chk_panel_wire_bend__position CHECK (position >= 0)');
        $this->addSql('ALTER TABLE panel_wire_bend ADD CONSTRAINT chk_panel_wire_bend__x CHECK (x_mm BETWEEN 0 AND 99999.9)');
        $this->addSql('ALTER TABLE panel_wire_bend ADD CONSTRAINT chk_panel_wire_bend__y CHECK (y_mm BETWEEN 0 AND 99999.9)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable DROP CONSTRAINT FK_24E9C4D46F6FCB26');
        $this->addSql('ALTER TABLE cable_wire DROP CONSTRAINT FK_3FDFE36F9A81106E');
        $this->addSql('ALTER TABLE din_rail DROP CONSTRAINT FK_DF087AB36F6FCB26');
        $this->addSql('ALTER TABLE element DROP CONSTRAINT FK_41405E3959E18326');
        $this->addSql('ALTER TABLE element_pole DROP CONSTRAINT FK_BE5E63181F1F2A24');
        $this->addSql('ALTER TABLE panel DROP CONSTRAINT FK_A2ADD30F7E3C61F9');
        $this->addSql('ALTER TABLE panel_wire DROP CONSTRAINT FK_167ED8D06F6FCB26');
        $this->addSql('ALTER TABLE panel_wire DROP CONSTRAINT FK_167ED8D06DD085DA');
        $this->addSql('ALTER TABLE panel_wire DROP CONSTRAINT FK_167ED8D04D6E3819');
        $this->addSql('ALTER TABLE panel_wire DROP CONSTRAINT FK_167ED8D0DBEB07D7');
        $this->addSql('ALTER TABLE panel_wire DROP CONSTRAINT FK_167ED8D0115772AA');
        $this->addSql('ALTER TABLE panel_wire DROP CONSTRAINT FK_167ED8D08BBEB235');
        $this->addSql('ALTER TABLE panel_wire DROP CONSTRAINT FK_167ED8D0A73B0E02');
        $this->addSql('ALTER TABLE panel_wire_bend DROP CONSTRAINT FK_3A11648E8DB2C7F5');
        $this->addSql('ALTER TABLE terminal_block DROP CONSTRAINT FK_3370C1A559E18326');
        $this->addSql('DROP TABLE cable');
        $this->addSql('DROP TABLE cable_wire');
        $this->addSql('DROP TABLE din_rail');
        $this->addSql('DROP TABLE element');
        $this->addSql('DROP TABLE element_pole');
        $this->addSql('DROP TABLE panel');
        $this->addSql('DROP TABLE panel_wire');
        $this->addSql('DROP TABLE panel_wire_bend');
        $this->addSql('DROP TABLE terminal_block');
    }
}
