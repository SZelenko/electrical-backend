<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Panel\AtsState;
use App\Entity\Panel\DeviceStyle;
use App\Entity\Panel\ElementKind;

/**
 * A device (`on` and `poles` set, `state` null, `style` set) or an ATS (`state` set, `style` null, `on` and `poles` null; fixed
 * 4 inputs and 2 outputs). All fields are always present; the ones that do not apply are null.
 */
final readonly class ElementDto
{
    /**
     * @param string             $label   at most 16 characters
     * @param string             $marking at most 6 characters
     * @param list<PoleDto>|null $poles   device: 1 to 4 x widthModules poles, the array index is the pole index; ATS: null
     */
    public function __construct(
        #[ApiProperty(required: true)]
        public ElementKind $kind,
        #[ApiProperty(required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public int $offsetModules,
        #[ApiProperty(required: true)]
        public int $widthModules,
        #[ApiProperty(required: true)]
        public string $label,
        #[ApiProperty(required: true)]
        public string $marking,
        #[ApiProperty(required: true)]
        public bool $sidesSwapped,
        #[ApiProperty(required: true)]
        public ?bool $on,
        #[ApiProperty(required: true)]
        public ?array $poles,
        #[ApiProperty(required: true)]
        public ?AtsState $state,
        #[ApiProperty(required: true)]
        public ?DeviceStyle $style,
    ) {}
}
