<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use App\Panel\Decimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'din_rail')]
#[ORM\UniqueConstraint(name: 'uniq_din_rail__panel_position', fields: ['panel', 'position'])]
class DinRail
{
    public const int SPACING_SCALE = 1;
    public const float MAX_SPACING_MM = 99999.9;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: Panel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Panel $panel;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $position;

    /** Distance from the previous rail in mm (for the first rail: from the top edge). */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 1)]
    public private(set) string $spacingMm;

    public function __construct(Uuid $id, Panel $panel, int $position, float $spacingMm)
    {
        $this->id = $id;
        $this->panel = $panel;
        $this->position = $position;
        $this->spacingMm = Decimal::format($spacingMm, self::SPACING_SCALE);
    }
}
