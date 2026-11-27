<?php

declare(strict_types=1);

namespace App\ApiResource\Panel;

use ApiPlatform\Metadata\ApiProperty;
use App\Panel\Content\PanelContent;

final readonly class SavePanelContentInput
{
    /** @param int $version the panel version the client last saw; a stale one gets 409 */
    public function __construct(
        #[ApiProperty(required: true)]
        public int $version,
        #[ApiProperty(required: true)]
        public PanelContent $content,
    ) {}
}
