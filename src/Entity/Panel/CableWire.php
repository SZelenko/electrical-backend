<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One wire of an input or output cable; a connection point for panel wires. */
#[ORM\Entity]
#[ORM\Table(name: 'cable_wire')]
#[ORM\UniqueConstraint(name: 'uniq_cable_wire__cable_position', fields: ['cable', 'position'])]
class CableWire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: Cable::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Cable $cable;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $position;

    #[ORM\Column(length: 12, enumType: WireColour::class)]
    public private(set) WireColour $colour;

    public function __construct(Uuid $id, Cable $cable, int $position, WireColour $colour)
    {
        $this->id = $id;
        $this->cable = $cable;
        $this->position = $position;
        $this->colour = $colour;
    }
}
