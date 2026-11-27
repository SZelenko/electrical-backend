<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use App\Panel\Decimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Input cable (rating: voltage / frequency / cross-section) or output cable (description); both carry a free label. */
#[ORM\Entity]
#[ORM\Table(name: 'cable')]
#[ORM\UniqueConstraint(name: 'uniq_cable__panel_kind_position', fields: ['panel', 'kind', 'position'])]
class Cable
{
    public const int INPUT_LABEL_SCALE_VOLTAGE = 1;
    public const int INPUT_LABEL_SCALE_FREQUENCY = 2;
    public const int INPUT_LABEL_SCALE_CROSS_SECTION = 2;
    public const float INPUT_LABEL_MAX_VOLTAGE = 99999.9;
    public const float INPUT_LABEL_MAX_FREQUENCY = 999.99;
    public const float INPUT_LABEL_MAX_CROSS_SECTION = 999.99;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Panel $panel;

    #[ORM\Column(length: 6, enumType: CableKind::class)]
    public private(set) CableKind $kind;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $position;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 1, nullable: true)]
    public private(set) ?string $voltageV = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    public private(set) ?string $frequencyHz = null;

    #[ORM\Column(name: 'cross_section_mm2', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    public private(set) ?string $crossSectionMm2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $description = null;

    /** The user's free mark shown on the cable box (at most 16 characters, may be empty); not the input-cable rating. */
    #[ORM\Column(length: 16)]
    public private(set) string $label;

    private function __construct(Uuid $id, Panel $panel, CableKind $kind, int $position, string $label)
    {
        $this->id = $id;
        $this->label = $label;
        $this->panel = $panel;
        $this->kind = $kind;
        $this->position = $position;
    }

    public static function input(
        Uuid $id,
        Panel $panel,
        int $position,
        string $label,
        float $voltageV,
        float $frequencyHz,
        float $crossSectionMm2,
    ): self {
        $cable = new self($id, $panel, CableKind::Input, $position, $label);
        $cable->voltageV = Decimal::format($voltageV, self::INPUT_LABEL_SCALE_VOLTAGE);
        $cable->frequencyHz = Decimal::format($frequencyHz, self::INPUT_LABEL_SCALE_FREQUENCY);
        $cable->crossSectionMm2 = Decimal::format($crossSectionMm2, self::INPUT_LABEL_SCALE_CROSS_SECTION);

        return $cable;
    }

    public static function output(Uuid $id, Panel $panel, int $position, string $label, string $description): self
    {
        $cable = new self($id, $panel, CableKind::Output, $position, $label);
        $cable->description = $description;

        return $cable;
    }
}
