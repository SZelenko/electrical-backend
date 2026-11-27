<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel\ElementKind;
use App\Panel\Content\BusbarDto;
use App\Panel\Content\ElementDto;
use App\Panel\Content\RailDto;
use App\Panel\Content\TerminalBlockDto;

/**
 * Pure helper: walks the items of a rail (elements and rail blocks together, in offset order) from
 * a busbar's anchor and returns the covered set. Used by the validator (which reports `reason`) and
 * by the short-circuit check (which only needs the devices of a valid walk).
 */
final readonly class BusbarCoverage
{
    /**
     * @param list<ElementDto> $devices covered devices in offset order (the longest valid prefix)
     * @param BusbarCoverageReason $reason None when the busbar is valid
     */
    private function __construct(
        public array $devices,
        public BusbarCoverageReason $reason,
    ) {}

    /**
     * The rail's elements and rail blocks in offset order. Compute once per rail and pass it to
     * {@see walk()} for every busbar of that rail.
     *
     * @return list<ElementDto|TerminalBlockDto>
     */
    public static function railItems(RailDto $rail): array
    {
        /** @var list<ElementDto|TerminalBlockDto> $items */
        $items = [...$rail->elements, ...$rail->terminalBlocks];
        usort($items, static fn(ElementDto|TerminalBlockDto $a, ElementDto|TerminalBlockDto $b): int => $a->offsetModules <=> $b->offsetModules);

        return $items;
    }

    /** @param list<ElementDto|TerminalBlockDto> $items the rail's items in offset order, see {@see railItems()} */
    public static function walk(array $items, BusbarDto $busbar): self
    {
        $start = array_find_key($items, static fn(ElementDto|TerminalBlockDto $item): bool => $item instanceof ElementDto && $item->id === $busbar->firstElementId);
        $anchor = null === $start ? null : $items[$start];
        if (!$anchor instanceof ElementDto || ElementKind::Device !== $anchor->kind) {
            return new self([], BusbarCoverageReason::AnchorMissing);
        }

        $devices = [];
        $reason = BusbarCoverageReason::None;
        $previous = null;
        for ($i = 0; $i < $busbar->count; ++$i) {
            $item = $items[$start + $i] ?? null;
            if (null === $item) {
                $reason = BusbarCoverageReason::TooFew;
                break;
            }
            if (!$item instanceof ElementDto || ElementKind::Device !== $item->kind) {
                $reason = BusbarCoverageReason::ForeignItem;
                break;
            }
            if (null !== $previous && $item->offsetModules !== $previous->offsetModules + $previous->widthModules) {
                $reason = BusbarCoverageReason::Gap;
                break;
            }
            $devices[] = $previous = $item;
        }
        if (BusbarCoverageReason::None === $reason && !array_all($devices, static fn(ElementDto $d): bool => $d->sidesSwapped === $devices[0]->sidesSwapped)) {
            $reason = BusbarCoverageReason::MixedSides;
        }

        return new self($devices, $reason);
    }
}
