<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;

final readonly class PoleDto
{
    public function __construct(
        #[ApiProperty(required: true)]
        public bool $transit,
    ) {}
}
