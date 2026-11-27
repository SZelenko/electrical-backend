<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/**
 * Value object: one end of a panel wire. Exactly one of three shapes: a terminal of an element
 * pole (element + pole index + logical side), a terminal of a terminal-block bar (block + index + contact side)
 * or a wire of an input/output cable.
 */
final readonly class WireEndpoint
{
    private function __construct(
        public ?Element $element,
        public ?int $poleIndex,
        public ?TerminalSide $side,
        public ?TerminalBlock $terminalBlock,
        public ?int $terminalIndex,
        public ?CableWire $cableWire,
        public ?ContactSide $contactSide,
    ) {}

    public static function pole(Element $element, int $poleIndex, TerminalSide $side): self
    {
        return new self($element, $poleIndex, $side, null, null, null, null);
    }

    public static function terminal(TerminalBlock $block, int $terminalIndex, ContactSide $contactSide): self
    {
        return new self(null, null, null, $block, $terminalIndex, null, $contactSide);
    }

    public static function cableWire(CableWire $wire): self
    {
        return new self(null, null, null, null, null, $wire, null);
    }

    /** Rebuilds the value object from the seven mapped columns of a stored wire end. */
    public static function fromColumns(
        ?Element $element,
        ?int $poleIndex,
        ?TerminalSide $side,
        ?TerminalBlock $terminalBlock,
        ?int $terminalIndex,
        ?CableWire $cableWire,
        ?ContactSide $contactSide,
    ): self {
        return new self($element, $poleIndex, $side, $terminalBlock, $terminalIndex, $cableWire, $contactSide);
    }

    public function kind(): EndpointKind
    {
        return match (true) {
            null !== $this->element => EndpointKind::Pole,
            null !== $this->terminalBlock => EndpointKind::Terminal,
            default => EndpointKind::CableWire,
        };
    }
}
