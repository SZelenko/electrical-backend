<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

final readonly class RailDto
{
    /**
     * @param float                   $spacingMm    distance from the previous rail (first rail: from the top edge), 0 <= s <= 99999.9, one decimal
     * @param list<ElementDto>        $elements
     * @param list<TerminalBlockDto>  $terminalBlocks
     * @param list<BusbarDto>         $busbars        at most 30, ordered by the offset of the anchor element in responses
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public float $spacingMm,
        #[ApiProperty(required: true)]
        public array $elements,
        #[ApiProperty(required: true)]
        public array $terminalBlocks,
        #[ApiProperty(required: true)]
        public array $busbars,
    ) {}
}
