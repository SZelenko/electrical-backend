<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A terminal-block bar: all its terminals are electrically joined. It either sits on a DIN rail
 * (`rail` set, one module wide, 1-12 terminals) or in the terminal row (`rail` null, 1-60
 * terminals, width ceil(terminals / 2)). The panel is set for every block.
 */
#[ORM\Entity]
#[ORM\Table(name: 'terminal_block')]
class TerminalBlock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Panel $panel;

    #[ORM\ManyToOne(targetEntity: DinRail::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public private(set) ?DinRail $rail;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $offsetModules;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $widthModules;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $terminalCount;

    /** Null = grey. */
    #[ORM\Column(length: 12, nullable: true, enumType: WireColour::class)]
    public private(set) ?WireColour $colour;

    #[ORM\Column(length: 16)]
    public private(set) string $label;

    private function __construct(
        Uuid $id,
        Panel $panel,
        ?DinRail $rail,
        int $offsetModules,
        int $widthModules,
        int $terminalCount,
        ?WireColour $colour,
        string $label,
    ) {
        $this->id = $id;
        $this->panel = $panel;
        $this->rail = $rail;
        $this->offsetModules = $offsetModules;
        $this->widthModules = $widthModules;
        $this->terminalCount = $terminalCount;
        $this->colour = $colour;
        $this->label = $label;
    }

    public static function onRail(
        Uuid $id,
        DinRail $rail,
        int $offsetModules,
        int $widthModules,
        int $terminalCount,
        ?WireColour $colour,
        string $label,
    ): self {
        return new self($id, $rail->panel, $rail, $offsetModules, $widthModules, $terminalCount, $colour, $label);
    }

    public static function inRow(
        Uuid $id,
        Panel $panel,
        int $offsetModules,
        int $widthModules,
        int $terminalCount,
        ?WireColour $colour,
        string $label,
    ): self {
        return new self($id, $panel, null, $offsetModules, $widthModules, $terminalCount, $colour, $label);
    }
}
