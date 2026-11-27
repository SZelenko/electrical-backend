<?php

declare(strict_types=1);

namespace App\ApiResource\Panel;

use ApiPlatform\Metadata\ApiProperty;
use App\Panel\PanelSummary;

/** Lean list entry of `GET /panels` and `GET /panels/trash` (no content). */
final readonly class PanelListItem
{
    public function __construct(
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public string $name,
        #[ApiProperty(required: true)]
        public int $version,
        #[ApiProperty(required: true)]
        public \DateTimeImmutable $createdAt,
        #[ApiProperty(required: true)]
        public \DateTimeImmutable $updatedAt,
        #[ApiProperty(required: true)]
        public ?\DateTimeImmutable $deletedAt,
    ) {}

    public static function fromSummary(PanelSummary $summary): self
    {
        return new self(
            $summary->id->toRfc4122(),
            $summary->name,
            $summary->version,
            $summary->createdAt,
            $summary->updatedAt,
            $summary->deletedAt,
        );
    }
}
