<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

final readonly class RailDto
{
    /**
     * @param int                     $widthModules 1 to 60
     * @param float                   $spacingMm    distance from the previous rail (first rail: from the top edge), 0 <= s <= 99999.9, one decimal
     * @param list<ElementDto>        $elements
     * @param list<TerminalBlockDto>  $terminalBlocks
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public int $widthModules,
        #[ApiProperty(required: true)]
        public float $spacingMm,
        #[ApiProperty(required: true)]
        public array $elements,
        #[ApiProperty(required: true)]
        public array $terminalBlocks,
    ) {}
}
