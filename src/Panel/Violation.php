<?php

declare(strict_types=1);

namespace App\Panel;

/** One validation failure: where (payload path), what, and the id of the offending item if it has one. */
final readonly class Violation
{
    public function __construct(
        public string $propertyPath,
        public string $message,
        public ?string $id = null,
    ) {}
}
