<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The whole content tree of a panel: the body of `PUT /panels/{id}/content`, the `content` of every
 * panel response and the `panel` of the shared short-circuit fixtures. Positions are the array
 * order; elements and terminal blocks of a rail are ordered by offset in responses.
 */
final readonly class PanelContent
{
    /**
     * @param list<InputCableDto>  $inputCables
     * @param list<OutputCableDto> $outputCables
     * @param list<TerminalBlockDto> $terminalRow blocks of the terminal row (between the input cables and the first rail), at most 60
     * @param list<RailDto>        $rails
     * @param list<WireDto>        $wires
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public array $inputCables,
        #[ApiProperty(required: true)]
        public array $outputCables,
        #[ApiProperty(required: true)]
        public array $terminalRow,
        #[ApiProperty(required: true)]
        public array $rails,
        #[ApiProperty(required: true)]
        public array $wires,
    ) {}
}
