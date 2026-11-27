<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

final readonly class BendDto
{
    /**
     * @param float $x mm from the left edge, 0 <= x <= 99999.9, one decimal
     * @param float $y mm from the top edge, 0 <= y <= 99999.9, one decimal
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public float $x,
        #[ApiProperty(required: true)]
        public float $y,
    ) {}
}
