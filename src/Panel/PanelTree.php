<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel\Cable;
use App\Entity\Panel\CableWire;
use App\Entity\Panel\DinRail;
use App\Entity\Panel\Element;
use App\Entity\Panel\ElementPole;
use App\Entity\Panel\PanelWire;
use App\Entity\Panel\PanelWireBend;
use App\Entity\Panel\TerminalBlock;

/** The child rows of one panel as flat typed lists (the root holds no child collections). */
final readonly class PanelTree
{
    /**
     * @param list<Cable>          $cables
     * @param list<CableWire>      $cableWires
     * @param list<DinRail>        $rails
     * @param list<Element>        $elements
     * @param list<ElementPole>    $poles
     * @param list<TerminalBlock>  $terminalBlocks
     * @param list<PanelWire>      $wires
     * @param list<PanelWireBend>  $bends
     */
    public function __construct(
        public array $cables,
        public array $cableWires,
        public array $rails,
        public array $elements,
        public array $poles,
        public array $terminalBlocks,
        public array $wires,
        public array $bends,
    ) {}

    /** @return list<object> every row, parents before children */
    public function all(): array
    {
        return [
            ...$this->cables,
            ...$this->cableWires,
            ...$this->rails,
            ...$this->elements,
            ...$this->poles,
            ...$this->terminalBlocks,
            ...$this->wires,
            ...$this->bends,
        ];
    }
}
