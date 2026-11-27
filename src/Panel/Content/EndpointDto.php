<?php

declare(strict_types=1);

namespace App\Panel\Content;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Panel\ContactSide;
use App\Entity\Panel\EndpointKind;
use App\Entity\Panel\TerminalSide;

/**
 * One end of a panel wire, a union discriminated by `kind`:
 * `pole` = elementId + poleIndex + side (devices and ATS; ATS inputs 0..3, outputs 0..1);
 * `terminal` = terminalBlockId + terminalIndex + contactSide (top/bottom on a terminal-row block,
 * left/right on a rail block); `cableWire` = cableWireId. Fields of the other kinds must be absent or null.
 */
final readonly class EndpointDto
{
    public function __construct(
        #[ApiProperty(required: true)]
        public EndpointKind $kind,
        public ?string $elementId = null,
        public ?int $poleIndex = null,
        public ?TerminalSide $side = null,
        public ?string $terminalBlockId = null,
        public ?int $terminalIndex = null,
        public ?string $cableWireId = null,
        public ?ContactSide $contactSide = null,
    ) {}

    /** The same endpoint without the drawing-only contact side. */
    public function withoutContactSide(): self
    {
        return new self(
            $this->kind,
            $this->elementId,
            $this->poleIndex,
            $this->side,
            $this->terminalBlockId,
            $this->terminalIndex,
            $this->cableWireId,
        );
    }

    /** Stable identity of the electrical node this endpoint denotes (the contact side is not part of it). */
    public function key(): string
    {
        return match ($this->kind) {
            EndpointKind::Pole => 'p:' . $this->elementId . ':' . $this->poleIndex . ':' . $this->side?->value,
            EndpointKind::Terminal => 't:' . $this->terminalBlockId . ':' . $this->terminalIndex,
            EndpointKind::CableWire => 'w:' . $this->cableWireId,
        };
    }
}
