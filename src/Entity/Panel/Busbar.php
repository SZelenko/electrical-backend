<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A busbar lying on the input edge of `deviceCount` consecutive devices of one rail, starting at
 * the anchor element (`firstElement`). The rail is the anchor's rail and the order is the anchor's
 * offset, so neither is stored. Feed 0 is L, feed 1 (LN only) is N.
 */
#[ORM\Entity]
#[ORM\Table(name: 'busbar')]
#[ORM\UniqueConstraint(name: 'uniq_busbar__first_element', fields: ['firstElement'])]
class Busbar
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: Element::class)]
    #[ORM\JoinColumn(name: 'first_element_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Element $firstElement;

    #[ORM\Column(length: 2, enumType: BusbarType::class)]
    public private(set) BusbarType $type;

    #[ORM\Column(length: 12, nullable: true, enumType: WireColour::class)]
    public private(set) ?WireColour $colour;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $deviceCount;

    public function __construct(Uuid $id, Element $firstElement, BusbarType $type, ?WireColour $colour, int $deviceCount)
    {
        $this->id = $id;
        $this->firstElement = $firstElement;
        $this->type = $type;
        $this->colour = $colour;
        $this->deviceCount = $deviceCount;
    }
}
