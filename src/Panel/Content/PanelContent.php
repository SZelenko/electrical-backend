<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Panel\CableSide;

/**
 * The whole content tree of a panel: the body of `PUT /panels/{id}/content`, the `content` of every
 * panel response and the `panel` of the shared short-circuit fixtures. Positions are the array
 * order; elements and terminal blocks of a rail are ordered by offset in responses.
 */
final readonly class PanelContent
{
    /**
     * @param int                    $railWidthModules  width of every rail and of both terminal rows, 1 to 60
     * @param list<InputCableDto>    $inputCables
     * @param list<OutputCableDto>   $outputCables
     * @param list<TerminalBlockDto> $terminalRow       blocks of the top terminal row (offsets from the rail start, capacity = railWidthModules), at most 60
     * @param list<TerminalBlockDto> $bottomTerminalRow blocks of the bottom terminal row, same rules as the top row
     * @param list<RailDto>          $rails
     * @param list<WireDto>          $wires
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public int $railWidthModules,
        #[ApiProperty(required: true)]
        public CableSide $inputCableSide,
        #[ApiProperty(required: true)]
        public CableSide $outputCableSide,
        #[ApiProperty(required: true)]
        public array $inputCables,
        #[ApiProperty(required: true)]
        public array $outputCables,
        #[ApiProperty(required: true)]
        public array $terminalRow,
        #[ApiProperty(required: true)]
        public array $bottomTerminalRow,
        #[ApiProperty(required: true)]
        public array $rails,
        #[ApiProperty(required: true)]
        public array $wires,
    ) {}
}
