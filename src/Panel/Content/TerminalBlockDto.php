<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Panel\WireColour;

/**
 * A terminal block on a rail (`widthModules` 1, 1 to 12 terminals) or in the terminal row (1 to 60
 * terminals, `widthModules` = ceil(terminalCount / 2)).
 */
final readonly class TerminalBlockDto
{
    /**
     * @param WireColour|null $colour null = grey
     * @param string          $label  at most 16 characters
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public int $offsetModules,
        #[ApiProperty(required: true)]
        public int $widthModules,
        #[ApiProperty(required: true)]
        public int $terminalCount,
        #[ApiProperty(required: true)]
        public ?WireColour $colour,
        #[ApiProperty(required: true)]
        public string $label,
    ) {}
}
