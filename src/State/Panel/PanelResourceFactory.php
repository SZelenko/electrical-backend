<?php

declare(strict_types=1);

namespace App\State\Panel;

use App\ApiResource\Panel\PanelResource;
use App\Panel\PanelDetails;
use App\Panel\ShortCircuitChecker;

/** Builds the panel response; short circuits are computed on every response, never stored. */
final readonly class PanelResourceFactory
{
    public function __construct(private ShortCircuitChecker $shortCircuits) {}

    public function create(PanelDetails $details): PanelResource
    {
        $panel = $details->panel;

        return new PanelResource(
            $panel->id->toRfc4122(),
            $panel->name,
            $panel->version,
            $panel->createdAt,
            $panel->updatedAt,
            $panel->deletedAt,
            $details->content,
            $this->shortCircuits->check($details->content),
        );
    }
}
