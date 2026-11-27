<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Panel\WireColour;
use App\Entity\Panel\WireCrossSection;

final readonly class WireDto
{
    /** @param list<BendDto> $bends route bend points in order, at most 50 */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public WireColour $colour,
        #[ApiProperty(required: true)]
        public WireCrossSection $crossSection,
        #[ApiProperty(required: true)]
        public EndpointDto $start,
        #[ApiProperty(required: true)]
        public EndpointDto $end,
        #[ApiProperty(required: true)]
        public array $bends = [],
    ) {}
}
