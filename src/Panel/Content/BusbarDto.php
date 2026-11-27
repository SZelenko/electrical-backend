<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Panel\BusbarType;
use App\Entity\Panel\WireColour;

/**
 * A busbar on the input edge of `count` consecutive devices of its rail, starting at the device
 * `firstElementId` (the anchor) and going right.
 */
final readonly class BusbarDto
{
    /**
     * @param WireColour|null $colour null = grey
     * @param int             $count number of covered devices, 2 to 60
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public BusbarType $type,
        #[ApiProperty(required: true)]
        public ?WireColour $colour,
        #[ApiProperty(required: true)]
        public string $firstElementId,
        #[ApiProperty(required: true)]
        public int $count,
    ) {}
}
