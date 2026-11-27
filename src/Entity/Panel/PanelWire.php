<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A wire drawn in the panel. Each end is stored as flat columns (start_* / end_*); exactly one of
 * element (+ pole index + side), terminal block (+ terminal index + contact side), cable wire and busbar (+ feed index) is set per end
 * (enforced by CHECK constraints in the migration, by the content validator in the app).
 * The WireEndpoint value objects `start` / `end` are views over those columns.
 */
#[ORM\Entity]
#[ORM\Table(name: 'panel_wire')]
#[ORM\UniqueConstraint(name: 'uniq_panel_wire__panel_position', fields: ['panel', 'position'])]
class PanelWire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Panel $panel;

    /** Draw order. */
    #[ORM\Column]
    public private(set) int $position;

    #[ORM\Column(length: 12, enumType: WireColour::class)]
    public private(set) WireColour $colour;

    #[ORM\Column(length: 3, enumType: WireCrossSection::class)]
    public private(set) WireCrossSection $crossSection;

    #[ORM\ManyToOne(targetEntity: Element::class)]
    #[ORM\JoinColumn(name: 'start_element_id', nullable: true, onDelete: 'CASCADE')]
    private ?Element $startElement = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $startPoleIndex = null;

    #[ORM\Column(length: 6, nullable: true, enumType: TerminalSide::class)]
    private ?TerminalSide $startPoleSide = null;

    #[ORM\ManyToOne(targetEntity: TerminalBlock::class)]
    #[ORM\JoinColumn(name: 'start_terminal_block_id', nullable: true, onDelete: 'CASCADE')]
    private ?TerminalBlock $startTerminalBlock = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $startTerminalIndex = null;

    #[ORM\Column(length: 6, nullable: true, enumType: ContactSide::class)]
    private ?ContactSide $startContactSide = null;

    #[ORM\ManyToOne(targetEntity: CableWire::class)]
    #[ORM\JoinColumn(name: 'start_cable_wire_id', nullable: true, onDelete: 'CASCADE')]
    private ?CableWire $startCableWire = null;

    #[ORM\ManyToOne(targetEntity: Busbar::class)]
    #[ORM\JoinColumn(name: 'start_busbar_id', nullable: true, onDelete: 'CASCADE')]
    private ?Busbar $startBusbar = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $startBusbarFeedIndex = null;

    #[ORM\ManyToOne(targetEntity: Element::class)]
    #[ORM\JoinColumn(name: 'end_element_id', nullable: true, onDelete: 'CASCADE')]
    private ?Element $endElement = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $endPoleIndex = null;

    #[ORM\Column(length: 6, nullable: true, enumType: TerminalSide::class)]
    private ?TerminalSide $endPoleSide = null;

    #[ORM\ManyToOne(targetEntity: TerminalBlock::class)]
    #[ORM\JoinColumn(name: 'end_terminal_block_id', nullable: true, onDelete: 'CASCADE')]
    private ?TerminalBlock $endTerminalBlock = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $endTerminalIndex = null;

    #[ORM\Column(length: 6, nullable: true, enumType: ContactSide::class)]
    private ?ContactSide $endContactSide = null;

    #[ORM\ManyToOne(targetEntity: CableWire::class)]
    #[ORM\JoinColumn(name: 'end_cable_wire_id', nullable: true, onDelete: 'CASCADE')]
    private ?CableWire $endCableWire = null;

    #[ORM\ManyToOne(targetEntity: Busbar::class)]
    #[ORM\JoinColumn(name: 'end_busbar_id', nullable: true, onDelete: 'CASCADE')]
    private ?Busbar $endBusbar = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $endBusbarFeedIndex = null;

    public WireEndpoint $start {
        get => WireEndpoint::fromColumns(
            $this->startElement,
            $this->startPoleIndex,
            $this->startPoleSide,
            $this->startTerminalBlock,
            $this->startTerminalIndex,
            $this->startCableWire,
            $this->startContactSide,
            $this->startBusbar,
            $this->startBusbarFeedIndex,
        );
    }

    public WireEndpoint $end {
        get => WireEndpoint::fromColumns(
            $this->endElement,
            $this->endPoleIndex,
            $this->endPoleSide,
            $this->endTerminalBlock,
            $this->endTerminalIndex,
            $this->endCableWire,
            $this->endContactSide,
            $this->endBusbar,
            $this->endBusbarFeedIndex,
        );
    }

    public function __construct(
        Uuid $id,
        Panel $panel,
        int $position,
        WireColour $colour,
        WireCrossSection $crossSection,
        WireEndpoint $start,
        WireEndpoint $end,
    ) {
        $this->id = $id;
        $this->panel = $panel;
        $this->position = $position;
        $this->colour = $colour;
        $this->crossSection = $crossSection;

        $this->startElement = $start->element;
        $this->startPoleIndex = $start->poleIndex;
        $this->startPoleSide = $start->side;
        $this->startTerminalBlock = $start->terminalBlock;
        $this->startTerminalIndex = $start->terminalIndex;
        $this->startCableWire = $start->cableWire;
        $this->startContactSide = $start->contactSide;
        $this->startBusbar = $start->busbar;
        $this->startBusbarFeedIndex = $start->busbarFeedIndex;

        $this->endElement = $end->element;
        $this->endPoleIndex = $end->poleIndex;
        $this->endPoleSide = $end->side;
        $this->endTerminalBlock = $end->terminalBlock;
        $this->endTerminalIndex = $end->terminalIndex;
        $this->endCableWire = $end->cableWire;
        $this->endContactSide = $end->contactSide;
        $this->endBusbar = $end->busbar;
        $this->endBusbarFeedIndex = $end->busbarFeedIndex;
    }
}
