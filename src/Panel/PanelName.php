<?php

declare(strict_types=1);

namespace App\Panel;

/** A validated panel name: the stored display value and the case-folded uniqueness key. */
final readonly class PanelName
{
    public function __construct(
        public string $value,
        public string $key,
    ) {}
}
