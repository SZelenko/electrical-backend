<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel\Panel;
use App\Panel\Content\PanelContent;

/** A panel together with its content tree, as returned by every use case that responds with a panel. */
final readonly class PanelDetails
{
    public function __construct(
        public Panel $panel,
        public PanelContent $content,
    ) {}
}
