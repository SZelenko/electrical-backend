<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel\AtsState;
use App\Entity\Panel\ElementKind;
use App\Panel\Content\EndpointDto;
use App\Panel\Content\TerminalBlockDto;
use App\Panel\Content\PanelContent;
use App\Panel\Content\ShortCircuitGroup;

/**
 * Pure short-circuit check (domain service, no I/O): union-find over electrical nodes.
 *
 * Every wire of every input cable is a separate source potential. Panel wires join their two
 * endpoints, a device pole joins its input and output terminal when the device is on or the pole
 * is transit, an ATS joins out1-in1 and out2-in2 in state A, out1-in3 and out2-in4 in state B and
 * nothing when off (it never joins sources A and B itself), a terminal block (on a rail or in the
 * terminal row) joins all its terminals. A short circuit is any connected group holding two or more
 * different sources. Colours, the sides-swapped flag and the contact side play no part.
 */
final readonly class ShortCircuitChecker
{
    /** @return list<ShortCircuitGroup> groups sorted by their first source id */
    public function check(PanelContent $content): array
    {
        /** @var array<string, string> $parent */
        $parent = [];

        $find = static function (string $node) use (&$parent): string {
            $parent[$node] ??= $node;
            $root = $node;
            while ($parent[$root] !== $root) {
                $root = $parent[$root];
            }
            while ($parent[$node] !== $root) {
                $next = $parent[$node];
                $parent[$node] = $root;
                $node = $next;
            }

            return $root;
        };
        $union = static function (string $a, string $b) use ($find, &$parent): void {
            $rootA = $find($a);
            $rootB = $find($b);
            if ($rootA !== $rootB) {
                $parent[$rootB] = $rootA;
            }
        };

        /** @var array<string, string> $sourceNodes node key => cable wire id */
        $sourceNodes = [];
        foreach ($content->inputCables as $cable) {
            foreach ($cable->wires as $wire) {
                $sourceNodes['w:' . $wire->id] = $wire->id;
                $find('w:' . $wire->id);
            }
        }

        foreach ($content->wires as $wire) {
            $union($wire->start->key(), $wire->end->key());
        }

        $joinBar = static function (TerminalBlockDto $block) use ($union): void {
            for ($index = 1; $index < $block->terminalCount; ++$index) {
                $union("t:{$block->id}:0", "t:{$block->id}:$index");
            }
        };
        foreach ($content->terminalRow as $block) {
            $joinBar($block);
        }
        foreach ($content->rails as $rail) {
            foreach ($rail->elements as $element) {
                if (ElementKind::Ats === $element->kind) {
                    $inputs = match ($element->state) {
                        AtsState::A => [0, 1],
                        AtsState::B => [2, 3],
                        // null is unreachable after validation (an ATS always has a state); treated as off
                        AtsState::Off, null => [],
                    };
                    foreach ($inputs as $output => $input) {
                        $union("p:{$element->id}:$input:input", "p:{$element->id}:$output:output");
                    }
                    continue;
                }
                foreach ($element->poles ?? [] as $index => $pole) {
                    if ($element->on || $pole->transit) {
                        $union("p:{$element->id}:$index:input", "p:{$element->id}:$index:output");
                    }
                }
            }
            foreach ($rail->terminalBlocks as $block) {
                $joinBar($block);
            }
        }

        /** @var array<string, list<string>> $sourcesByRoot */
        $sourcesByRoot = [];
        foreach ($sourceNodes as $node => $wireId) {
            $sourcesByRoot[$find($node)][] = $wireId;
        }
        $shorted = array_filter($sourcesByRoot, static fn(array $sources): bool => \count($sources) >= 2);
        if ([] === $shorted) {
            return [];
        }

        /** @var array<string, list<string>> $wiresByRoot */
        $wiresByRoot = [];
        /** @var array<string, array<string, EndpointDto>> $endpointsByRoot */
        $endpointsByRoot = [];
        foreach ($content->wires as $wire) {
            $root = $find($wire->start->key());
            if (!isset($shorted[$root])) {
                continue;
            }
            $wiresByRoot[$root][] = $wire->id;
            $endpointsByRoot[$root][$wire->start->key()] ??= $wire->start->withoutContactSide();
            $endpointsByRoot[$root][$wire->end->key()] ??= $wire->end->withoutContactSide();
        }

        $groups = [];
        foreach ($shorted as $root => $sources) {
            sort($sources);
            $wireIds = $wiresByRoot[$root] ?? [];
            sort($wireIds);
            $endpoints = $endpointsByRoot[$root] ?? [];
            ksort($endpoints);
            $groups[] = new ShortCircuitGroup($sources, $wireIds, array_values($endpoints));
        }
        usort($groups, static fn(ShortCircuitGroup $a, ShortCircuitGroup $b): int => $a->sources[0] <=> $b->sources[0]);

        return $groups;
    }
}
