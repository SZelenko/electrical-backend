<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

/** A connected group of terminals that joins two or more different source potentials. */
final readonly class ShortCircuitGroup
{
    /**
     * @param list<string>      $sources   ids of the input-cable wires in the group, sorted
     * @param list<string>      $wireIds   ids of the panel wires in the group, sorted
     * @param list<EndpointDto> $terminals endpoints the group's wires attach to, sorted by node key
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public array $sources,
        #[ApiProperty(required: true)]
        public array $wireIds,
        #[ApiProperty(required: true)]
        public array $terminals,
    ) {}
}
