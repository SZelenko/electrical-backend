<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

final readonly class InputCableDto
{
    /**
     * @param float              $voltage      V, 0 < v <= 99999.9, one decimal
     * @param float              $frequency    Hz, 0 <= f <= 999.99, two decimals
     * @param float              $crossSection mm², 0 < x <= 999.99, two decimals
     * @param list<CableWireDto> $wires        1 to 4 wires, each a separate source potential
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public float $voltage,
        #[ApiProperty(required: true)]
        public float $frequency,
        #[ApiProperty(required: true)]
        public float $crossSection,
        #[ApiProperty(required: true)]
        public array $wires,
    ) {}
}
