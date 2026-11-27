<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Panel\WireColour;

final readonly class CableWireDto
{
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public WireColour $colour,
    ) {}
}
