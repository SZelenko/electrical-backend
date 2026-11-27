<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An element on a DIN rail: a device (its poles are ElementPole rows addressed by index, on/off
 * flag) or an ATS (fixed 4 inputs / 2 outputs, no pole rows, switch state A/B/off).
 */
#[ORM\Entity]
#[ORM\Table(name: 'element')]
class Element
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: DinRail::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) DinRail $rail;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $offsetModules;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $widthModules;

    #[ORM\Column(length: 16)]
    public private(set) string $label;

    #[ORM\Column(length: 6)]
    public private(set) string $marking;

    #[ORM\Column(length: 6, enumType: ElementKind::class)]
    public private(set) ElementKind $kind;

    /** Device only (null for an ATS). */
    #[ORM\Column(name: 'is_on', nullable: true)]
    public private(set) ?bool $isOn;

    /** Device only (null for an ATS). */
    #[ORM\Column(length: 16, nullable: true, enumType: DeviceStyle::class)]
    public private(set) ?DeviceStyle $style;

    /** ATS only (null for a device). */
    #[ORM\Column(length: 3, nullable: true, enumType: AtsState::class)]
    public private(set) ?AtsState $atsState;

    #[ORM\Column]
    public private(set) bool $sidesSwapped;

    private function __construct(
        Uuid $id,
        DinRail $rail,
        int $offsetModules,
        int $widthModules,
        string $label,
        string $marking,
        ElementKind $kind,
        ?DeviceStyle $style,
        ?bool $isOn,
        ?AtsState $atsState,
        bool $sidesSwapped,
    ) {
        $this->id = $id;
        $this->rail = $rail;
        $this->offsetModules = $offsetModules;
        $this->widthModules = $widthModules;
        $this->label = $label;
        $this->marking = $marking;
        $this->kind = $kind;
        $this->style = $style;
        $this->isOn = $isOn;
        $this->atsState = $atsState;
        $this->sidesSwapped = $sidesSwapped;
    }

    public static function device(
        Uuid $id,
        DinRail $rail,
        int $offsetModules,
        int $widthModules,
        string $label,
        string $marking,
        DeviceStyle $style,
        bool $isOn,
        bool $sidesSwapped,
    ): self {
        return new self($id, $rail, $offsetModules, $widthModules, $label, $marking, ElementKind::Device, $style, $isOn, null, $sidesSwapped);
    }

    public static function ats(
        Uuid $id,
        DinRail $rail,
        int $offsetModules,
        int $widthModules,
        string $label,
        string $marking,
        AtsState $state,
        bool $sidesSwapped,
    ): self {
        return new self($id, $rail, $offsetModules, $widthModules, $label, $marking, ElementKind::Ats, null, null, $state, $sidesSwapped);
    }
}
