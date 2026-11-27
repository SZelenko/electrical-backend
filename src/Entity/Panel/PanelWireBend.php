<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use App\Panel\Decimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A user-placed bend point of a wire route (mm, origin top-left); identified by (wire, position). */
#[ORM\Entity]
#[ORM\Table(name: 'panel_wire_bend')]
class PanelWireBend
{
    public const int SCALE = 1;
    public const float MAX_COORDINATE_MM = 99999.9;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PanelWire::class)]
    #[ORM\JoinColumn(name: 'wire_id', onDelete: 'CASCADE')]
    public private(set) PanelWire $wire;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $position;

    #[ORM\Column(name: 'x_mm', type: Types::DECIMAL, precision: 7, scale: 1)]
    public private(set) string $xMm;

    #[ORM\Column(name: 'y_mm', type: Types::DECIMAL, precision: 7, scale: 1)]
    public private(set) string $yMm;

    public function __construct(PanelWire $wire, int $position, float $xMm, float $yMm)
    {
        $this->wire = $wire;
        $this->position = $position;
        $this->xMm = Decimal::format($xMm, self::SCALE);
        $this->yMm = Decimal::format($yMm, self::SCALE);
    }
}
