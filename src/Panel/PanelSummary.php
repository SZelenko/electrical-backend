<?php

declare(strict_types=1);

namespace App\Panel;

use Symfony\Component\Uid\Uuid;

/** Lean read model of a panel for lists (no content); built by a DQL NEW expression. */
final readonly class PanelSummary
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $deletedAt,
    ) {}
}
