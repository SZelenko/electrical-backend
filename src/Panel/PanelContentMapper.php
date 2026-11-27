<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel\Cable;
use App\Entity\Panel\CableKind;
use App\Entity\Panel\CableWire;
use App\Entity\Panel\DinRail;
use App\Entity\Panel\Element;
use App\Entity\Panel\ElementPole;
use App\Entity\Panel\ElementKind;
use App\Entity\Panel\EndpointKind;
use App\Entity\Panel\Panel;
use App\Entity\Panel\PanelWire;
use App\Entity\Panel\PanelWireBend;
use App\Entity\Panel\TerminalBlock;
use App\Entity\Panel\WireEndpoint;
use App\Panel\Content\BendDto;
use App\Panel\Content\CableWireDto;
use App\Panel\Content\ElementDto;
use App\Panel\Content\EndpointDto;
use App\Panel\Content\InputCableDto;
use App\Panel\Content\OutputCableDto;
use App\Panel\Content\PanelContent;
use App\Panel\Content\PoleDto;
use App\Panel\Content\RailDto;
use App\Panel\Content\TerminalBlockDto;
use App\Panel\Content\WireDto;
use Symfony\Component\Uid\Uuid;

/**
 * Maps between the content DTOs and the child entities. `build()` expects a payload that passed
 * PanelContentValidator (so every endpoint resolves inside the payload); positions come from the
 * array order. `toContent()` produces the canonical order: cables, rails, wires and bends by
 * position, elements and rail terminal blocks by offset, terminal-row blocks by offset.
 */
final readonly class PanelContentMapper
{
    public function build(Panel $panel, PanelContent $content): PanelTree
    {
        $cables = [];
        $cableWires = [];
        /** @var array<string, CableWire> $wireById */
        $wireById = [];

        foreach (self::values($content->inputCables) as $i => $dto) {
            $cable = Cable::input(Uuid::fromString($dto->id), $panel, $i, $dto->voltage, $dto->frequency, $dto->crossSection);
            $cables[] = $cable;
            foreach (self::values($dto->wires) as $j => $wireDto) {
                $wire = new CableWire(Uuid::fromString($wireDto->id), $cable, $j, $wireDto->colour);
                $cableWires[] = $wireById[$wireDto->id] = $wire;
            }
        }
        foreach (self::values($content->outputCables) as $i => $dto) {
            $cable = Cable::output(Uuid::fromString($dto->id), $panel, $i, $dto->description);
            $cables[] = $cable;
            foreach (self::values($dto->wires) as $j => $wireDto) {
                $wire = new CableWire(Uuid::fromString($wireDto->id), $cable, $j, $wireDto->colour);
                $cableWires[] = $wireById[$wireDto->id] = $wire;
            }
        }

        $rails = [];
        $elements = [];
        $poles = [];
        $blocks = [];
        /** @var array<string, Element> $elementById */
        $elementById = [];
        /** @var array<string, TerminalBlock> $blockById */
        $blockById = [];

        foreach (self::values($content->terminalRow) as $dto) {
            $block = TerminalBlock::inRow(
                Uuid::fromString($dto->id),
                $panel,
                $dto->offsetModules,
                $dto->widthModules,
                $dto->terminalCount,
                $dto->colour,
                $dto->label,
            );
            $blocks[] = $blockById[$dto->id] = $block;
        }

        foreach (self::values($content->rails) as $i => $railDto) {
            $rail = new DinRail(Uuid::fromString($railDto->id), $panel, $i, $railDto->widthModules, $railDto->spacingMm);
            $rails[] = $rail;
            foreach (self::values($railDto->elements) as $dto) {
                $element = ElementKind::Ats === $dto->kind
                    ? Element::ats(
                        Uuid::fromString($dto->id),
                        $rail,
                        $dto->offsetModules,
                        $dto->widthModules,
                        $dto->label,
                        $dto->marking,
                        $dto->state ?? throw new \LogicException('An ATS without a state passed validation.'),
                        $dto->sidesSwapped,
                    )
                    : Element::device(
                        Uuid::fromString($dto->id),
                        $rail,
                        $dto->offsetModules,
                        $dto->widthModules,
                        $dto->label,
                        $dto->marking,
                        $dto->style ?? throw new \LogicException('A device without a style passed validation.'),
                        $dto->on ?? throw new \LogicException('A device without an on flag passed validation.'),
                        $dto->sidesSwapped,
                    );
                $elements[] = $elementById[$dto->id] = $element;
                foreach (self::values($dto->poles ?? []) as $index => $poleDto) {
                    $poles[] = new ElementPole($element, $index, $poleDto->transit);
                }
            }
            foreach (self::values($railDto->terminalBlocks) as $dto) {
                $block = TerminalBlock::onRail(
                    Uuid::fromString($dto->id),
                    $rail,
                    $dto->offsetModules,
                    $dto->widthModules,
                    $dto->terminalCount,
                    $dto->colour,
                    $dto->label,
                );
                $blocks[] = $blockById[$dto->id] = $block;
            }
        }

        $wires = [];
        $bends = [];
        foreach (self::values($content->wires) as $i => $dto) {
            $wire = new PanelWire(
                Uuid::fromString($dto->id),
                $panel,
                $i,
                $dto->colour,
                $dto->crossSection,
                $this->resolve($dto->start, $elementById, $blockById, $wireById),
                $this->resolve($dto->end, $elementById, $blockById, $wireById),
            );
            $wires[] = $wire;
            foreach (self::values($dto->bends) as $k => $bend) {
                $bends[] = new PanelWireBend($wire, $k, $bend->x, $bend->y);
            }
        }

        return new PanelTree($cables, $cableWires, $rails, $elements, $poles, $blocks, $wires, $bends);
    }

    public function toContent(PanelTree $tree): PanelContent
    {
        /** @var array<string, list<CableWireDto>> $wiresByCable */
        $wiresByCable = [];
        $cableWires = $tree->cableWires;
        usort($cableWires, static fn(CableWire $a, CableWire $b): int => $a->position <=> $b->position);
        foreach ($cableWires as $wire) {
            $wiresByCable[$wire->cable->id->toRfc4122()][] = new CableWireDto($wire->id->toRfc4122(), $wire->colour);
        }

        $cables = $tree->cables;
        usort($cables, static fn(Cable $a, Cable $b): int => $a->position <=> $b->position);
        $inputCables = [];
        $outputCables = [];
        foreach ($cables as $cable) {
            $id = $cable->id->toRfc4122();
            $wires = $wiresByCable[$id] ?? [];
            if (CableKind::Input === $cable->kind) {
                $inputCables[] = new InputCableDto(
                    $id,
                    (float) $cable->voltageV,
                    (float) $cable->frequencyHz,
                    (float) $cable->crossSectionMm2,
                    $wires,
                );
            } else {
                $outputCables[] = new OutputCableDto($id, (string) $cable->description, $wires);
            }
        }

        /** @var array<string, list<PoleDto>> $polesByElement */
        $polesByElement = [];
        $poles = $tree->poles;
        usort($poles, static fn(ElementPole $a, ElementPole $b): int => $a->poleIndex <=> $b->poleIndex);
        foreach ($poles as $pole) {
            $polesByElement[$pole->element->id->toRfc4122()][] = new PoleDto($pole->isTransit);
        }

        /** @var array<string, list<array{int, ElementDto|TerminalBlockDto}>> $itemsByRail */
        $itemsByRail = [];
        foreach ($tree->elements as $element) {
            $id = $element->id->toRfc4122();
            $isAts = ElementKind::Ats === $element->kind;
            $itemsByRail[$element->rail->id->toRfc4122()][] = [$element->offsetModules, new ElementDto(
                $element->kind,
                $id,
                $element->offsetModules,
                $element->widthModules,
                $element->label,
                $element->marking,
                $element->sidesSwapped,
                $isAts ? null : $element->isOn,
                $isAts ? null : ($polesByElement[$id] ?? []),
                $element->atsState,
                $element->style,
            )];
        }
        /** @var list<array{int, TerminalBlockDto}> $rowBlocks */
        $rowBlocks = [];
        foreach ($tree->terminalBlocks as $block) {
            $dto = new TerminalBlockDto(
                $block->id->toRfc4122(),
                $block->offsetModules,
                $block->widthModules,
                $block->terminalCount,
                $block->colour,
                $block->label,
            );
            if (null === $block->rail) {
                $rowBlocks[] = [$block->offsetModules, $dto];
            } else {
                $itemsByRail[$block->rail->id->toRfc4122()][] = [$block->offsetModules, $dto];
            }
        }
        usort($rowBlocks, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $terminalRow = array_map(static fn(array $item): TerminalBlockDto => $item[1], $rowBlocks);

        $rails = $tree->rails;
        usort($rails, static fn(DinRail $a, DinRail $b): int => $a->position <=> $b->position);
        $railDtos = [];
        foreach ($rails as $rail) {
            $id = $rail->id->toRfc4122();
            $items = $itemsByRail[$id] ?? [];
            usort($items, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $railElements = [];
            $railBlocks = [];
            foreach ($items as [, $item]) {
                if ($item instanceof ElementDto) {
                    $railElements[] = $item;
                } else {
                    $railBlocks[] = $item;
                }
            }
            $railDtos[] = new RailDto($id, $rail->widthModules, (float) $rail->spacingMm, $railElements, $railBlocks);
        }

        /** @var array<string, list<BendDto>> $bendsByWire */
        $bendsByWire = [];
        $bends = $tree->bends;
        usort($bends, static fn(PanelWireBend $a, PanelWireBend $b): int => $a->position <=> $b->position);
        foreach ($bends as $bend) {
            $bendsByWire[$bend->wire->id->toRfc4122()][] = new BendDto((float) $bend->xMm, (float) $bend->yMm);
        }

        $wires = $tree->wires;
        usort($wires, static fn(PanelWire $a, PanelWire $b): int => $a->position <=> $b->position);
        $wireDtos = [];
        foreach ($wires as $wire) {
            $id = $wire->id->toRfc4122();
            $wireDtos[] = new WireDto(
                $id,
                $wire->colour,
                $wire->crossSection,
                $this->toEndpointDto($wire->start),
                $this->toEndpointDto($wire->end),
                $bendsByWire[$id] ?? [],
            );
        }

        return new PanelContent($inputCables, $outputCables, $terminalRow, $railDtos, $wireDtos);
    }

    /**
     * @param array<string, Element>       $elements
     * @param array<string, TerminalBlock> $blocks
     * @param array<string, CableWire>     $cableWires
     */
    private function resolve(EndpointDto $dto, array $elements, array $blocks, array $cableWires): WireEndpoint
    {
        return match ($dto->kind) {
            EndpointKind::Pole => WireEndpoint::pole(
                $elements[(string) $dto->elementId],
                (int) $dto->poleIndex,
                $dto->side ?? throw new \LogicException('A pole endpoint without a side passed validation.'),
            ),
            EndpointKind::Terminal => WireEndpoint::terminal(
                $blocks[(string) $dto->terminalBlockId],
                (int) $dto->terminalIndex,
                $dto->contactSide ?? throw new \LogicException('A terminal endpoint without a contact side passed validation.'),
            ),
            EndpointKind::CableWire => WireEndpoint::cableWire($cableWires[(string) $dto->cableWireId]),
        };
    }

    private function toEndpointDto(WireEndpoint $endpoint): EndpointDto
    {
        return match ($endpoint->kind()) {
            EndpointKind::Pole => new EndpointDto(
                EndpointKind::Pole,
                elementId: $endpoint->element?->id->toRfc4122(),
                poleIndex: $endpoint->poleIndex,
                side: $endpoint->side,
            ),
            EndpointKind::Terminal => new EndpointDto(
                EndpointKind::Terminal,
                terminalBlockId: $endpoint->terminalBlock?->id->toRfc4122(),
                terminalIndex: $endpoint->terminalIndex,
                contactSide: $endpoint->contactSide,
            ),
            EndpointKind::CableWire => new EndpointDto(
                EndpointKind::CableWire,
                cableWireId: $endpoint->cableWire?->id->toRfc4122(),
            ),
        };
    }

    /**
     * The serializer keeps JSON object keys of list properties (the validator rejects such payloads;
     * this is the second line of defence so positions are always contiguous).
     *
     * @template T
     *
     * @param array<array-key, T> $items
     *
     * @return list<T>
     */
    private static function values(array $items): array
    {
        return array_values($items);
    }
}
