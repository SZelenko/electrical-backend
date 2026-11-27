<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

final readonly class OutputCableDto
{
    /**
     * @param string             $description at most 255 characters
     * @param list<CableWireDto> $wires       1 to 4 wires
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public string $description,
        #[ApiProperty(required: true)]
        public array $wires,
    ) {}
}
