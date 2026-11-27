<?php

declare(strict_types=1);

namespace App\ApiResource\Panel;

use ApiPlatform\Metadata\ApiProperty;

final readonly class CreatePanelInput
{
    /** @param string $name trimmed, 1 to 100 characters, unique per owner among non-trashed panels (case-insensitive) */
    public function __construct(
        #[ApiProperty(required: true)]
        public string $name,
    ) {}
}
