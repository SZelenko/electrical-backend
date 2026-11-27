<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/**
 * Value object: one end of a panel wire. Exactly one of four shapes: a terminal of an element
 * pole (element + pole index + logical side), a terminal of a terminal-block bar (block + index + contact side),
 * a wire of an input/output cable or a feed of a busbar (busbar + feed index).
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
        public ?Busbar $busbar = null,
        public ?int $busbarFeedIndex = null,
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

    public static function busbar(Busbar $busbar, int $feedIndex): self
    {
        return new self(null, null, null, null, null, null, null, $busbar, $feedIndex);
    }

    /** Rebuilds the value object from the mapped columns of a stored wire end. */
    public static function fromColumns(
        ?Element $element,
        ?int $poleIndex,
        ?TerminalSide $side,
        ?TerminalBlock $terminalBlock,
        ?int $terminalIndex,
        ?CableWire $cableWire,
        ?ContactSide $contactSide,
        ?Busbar $busbar = null,
        ?int $busbarFeedIndex = null,
    ): self {
        return new self($element, $poleIndex, $side, $terminalBlock, $terminalIndex, $cableWire, $contactSide, $busbar, $busbarFeedIndex);
    }

    public function kind(): EndpointKind
    {
        return match (true) {
            null !== $this->element => EndpointKind::Pole,
            null !== $this->terminalBlock => EndpointKind::Terminal,
            null !== $this->busbar => EndpointKind::Busbar,
            default => EndpointKind::CableWire,
        };
    }
}
