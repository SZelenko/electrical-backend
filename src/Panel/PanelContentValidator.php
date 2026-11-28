<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel\Cable;
use App\Entity\Panel\ContactSide;
use App\Entity\Panel\DinRail;
use App\Entity\Panel\Panel;
use App\Entity\Panel\ElementKind;
use App\Entity\Panel\EndpointKind;
use App\Entity\Panel\PanelWireBend;
use App\Entity\Panel\TerminalSide;
use App\Panel\Content\BusbarDto;
use App\Panel\Content\CableWireDto;
use App\Panel\Content\ElementDto;
use App\Panel\Content\EndpointDto;
use App\Panel\Content\PanelContent;
use App\Panel\Content\RailDto;
use App\Panel\Content\TerminalBlockDto;
use App\Panel\Content\WireDto;

/**
 * Technical caps (R4, not domain limits): input cables, output cables, panel wires, bends per wire
 * and bends per panel (MAX_TOTAL_BENDS); each exceeded cap is a 422.
 *
 * Application-level validation of a whole panel content tree (everything the database cannot
 * check across rows). Shape and types are already guaranteed by deserialisation. Wire endpoints are
 * resolved only against ids declared in the same payload. Violations mirror the payload paths
 * (`content.rails[2].elements[0].widthModules`) and carry the offending item id where there is one.
 */
final readonly class PanelContentValidator
{
    public const int MAX_INPUT_CABLES = 20;
    public const int MAX_OUTPUT_CABLES = 100;
    public const int MAX_RAILS = 8;
    public const int MAX_WIRES = 5000;
    public const int MAX_BENDS_PER_WIRE = 50;
    public const int MAX_TOTAL_BENDS = 20000;
    public const int MAX_CABLE_WIRES = 4;
    public const int MAX_POLES_PER_MODULE = 4;
    public const int MAX_ROW_BLOCKS = 60;
    public const int MAX_BUSBARS_PER_RAIL = 30;
    public const int MIN_BUSBAR_COUNT = 2;
    public const int MAX_BUSBAR_COUNT = 60;
    public const int MAX_ROW_BLOCK_TERMINALS = 60;
    public const int MAX_RAIL_BLOCK_TERMINALS = 12;
    public const int ATS_MIN_WIDTH = 2;
    public const int ATS_INPUTS = 4;
    public const int ATS_OUTPUTS = 2;
    public const int MAX_LABEL_LENGTH = 16;
    public const int MAX_MARKING_LENGTH = 6;
    public const int MAX_DESCRIPTION_LENGTH = 255;

    /** @throws PanelValidationException */
    public function validate(PanelContent $content): void
    {
        $run = new ValidationRun();

        /** @var array<string, true> $cableWires */
        $cableWires = [];
        /** @var array<string, array{int, int}> $terminals element id => [input count, output count] */
        $terminals = [];
        /** @var array<string, array{int, bool}> $blocks block id => [terminal count, in the terminal row] */
        $blocks = [];

        /** @var array<string, int> $busbars busbar id => feed count */
        $busbars = [];

        $railWidthOk = $run->intRange(
            $content->railWidthModules,
            Panel::MIN_RAIL_WIDTH_MODULES,
            Panel::MAX_RAIL_WIDTH_MODULES,
            'content.railWidthModules',
        );
        $railWidth = $railWidthOk ? $content->railWidthModules : null;

        $this->validateCables($run, $content, $cableWires);
        $this->validateTerminalRow($run, $content->terminalRow, 'terminalRow', 'top', $blocks, $railWidth);
        $this->validateTerminalRow($run, $content->bottomTerminalRow, 'bottomTerminalRow', 'bottom', $blocks, $railWidth);
        $this->validateRails($run, $content, $terminals, $blocks, $busbars, $railWidth);
        $this->validateWires($run, $content, $cableWires, $terminals, $blocks, $busbars);

        $violations = $run->violations();
        if (null !== $violations) {
            throw new PanelValidationException($violations);
        }
    }

    /**
     * @param array<string, true> $cableWires
     */
    private function validateCables(ValidationRun $run, PanelContent $content, array &$cableWires): void
    {
        $inputCables = $this->listOrViolation($run, $content->inputCables, 'content.inputCables');
        $outputCables = $this->listOrViolation($run, $content->outputCables, 'content.outputCables');

        $inputs = \count($inputCables);
        if (self::isList($content->inputCables)) {
            if ($inputs < 1) {
                $run->add('content.inputCables', 'At least one input cable is required.');
            } elseif ($inputs > self::MAX_INPUT_CABLES) {
                $run->add('content.inputCables', \sprintf('At most %d input cables are allowed.', self::MAX_INPUT_CABLES));
            }
        }

        foreach ($inputCables as $i => $cable) {
            $path = "content.inputCables[$i]";
            $run->claimId($cable->id, "$path.id");
            $run->maxLength($cable->label, self::MAX_LABEL_LENGTH, "$path.label", $cable->id);
            $run->decimalRange($cable->voltage, 0.0, true, Cable::INPUT_LABEL_MAX_VOLTAGE, Cable::INPUT_LABEL_SCALE_VOLTAGE, "$path.voltage", $cable->id);
            $run->decimalRange($cable->frequency, 0.0, false, Cable::INPUT_LABEL_MAX_FREQUENCY, Cable::INPUT_LABEL_SCALE_FREQUENCY, "$path.frequency", $cable->id);
            $run->decimalRange($cable->crossSection, 0.0, true, Cable::INPUT_LABEL_MAX_CROSS_SECTION, Cable::INPUT_LABEL_SCALE_CROSS_SECTION, "$path.crossSection", $cable->id);
            $this->validateCableWires($run, $cable->wires, $path, $cableWires);
        }

        if (\count($outputCables) > self::MAX_OUTPUT_CABLES) {
            $run->add('content.outputCables', \sprintf('At most %d output cables are allowed.', self::MAX_OUTPUT_CABLES));
        }

        foreach ($outputCables as $i => $cable) {
            $path = "content.outputCables[$i]";
            $run->claimId($cable->id, "$path.id");
            $run->maxLength($cable->label, self::MAX_LABEL_LENGTH, "$path.label", $cable->id);
            $run->maxLength($cable->description, self::MAX_DESCRIPTION_LENGTH, "$path.description", $cable->id);
            $this->validateCableWires($run, $cable->wires, $path, $cableWires);
        }
    }

    /**
     * @param list<CableWireDto>   $wires
     * @param array<string, true>  $cableWires
     */
    private function validateCableWires(ValidationRun $run, array $wires, string $path, array &$cableWires): void
    {
        if (!self::isList($wires)) {
            $run->add("$path.wires", 'Must be a JSON array (list), not an object.');

            return;
        }
        $count = \count($wires);
        if ($count < 1 || $count > self::MAX_CABLE_WIRES) {
            $run->add("$path.wires", \sprintf('A cable has 1 to %d wires.', self::MAX_CABLE_WIRES));
        }
        foreach ($wires as $j => $wire) {
            $run->claimId($wire->id, "$path.wires[$j].id");
            $cableWires[$wire->id] = true;
        }
    }

    /**
     * @param list<TerminalBlockDto>          $blocksDto   the row's blocks as received (may not be a real list)
     * @param string                          $property    content property holding the row
     * @param string                          $rowName     "top" or "bottom", used in messages
     * @param array<string, array{int, bool}> $blocks
     * @param int|null                        $rowCapacity rail width, null when it is invalid
     */
    private function validateTerminalRow(
        ValidationRun $run,
        array $blocksDto,
        string $property,
        string $rowName,
        array &$blocks,
        ?int $rowCapacity,
    ): void {
        $row = $this->listOrViolation($run, $blocksDto, "content.$property");
        if (\count($row) > self::MAX_ROW_BLOCKS) {
            $run->add("content.$property", \sprintf('At most %d terminal blocks are allowed in the %s terminal row.', self::MAX_ROW_BLOCKS, $rowName));
        }

        /** @var list<array{int, int, string, string}> $spans */
        $spans = [];
        foreach ($row as $i => $block) {
            $path = "content.{$property}[$i]";
            $run->claimId($block->id, "$path.id");
            $run->maxLength($block->label, self::MAX_LABEL_LENGTH, "$path.label", $block->id);
            $countOk = $run->intRange(
                $block->terminalCount,
                1,
                self::MAX_ROW_BLOCK_TERMINALS,
                "$path.terminalCount",
                $block->id,
            );
            if ($countOk && $block->widthModules >= 1 && $block->widthModules !== intdiv($block->terminalCount + 1, 2)) {
                $run->add(
                    "$path.widthModules",
                    \sprintf('Must be %d (half of the terminal count, rounded up).', intdiv($block->terminalCount + 1, 2)),
                    $block->id,
                );
            }
            $blocks[$block->id] = [$block->terminalCount, true];
            $this->collectSpan(
                $run,
                $spans,
                $rowCapacity,
                $block->id,
                $block->offsetModules,
                $block->widthModules,
                $path,
                \sprintf('Does not fit within the %s terminal row (rail width).', $rowName),
            );
        }

        $this->checkOverlaps($run, $spans, \sprintf('Overlaps another block in the %s terminal row.', $rowName));
    }

    /**
     * @param array<string, array{int, int}>  $terminals
     * @param array<string, array{int, bool}> $blocks
     * @param array<string, int>              $busbars   busbar id => feed count
     */
    private function validateRails(
        ValidationRun $run,
        PanelContent $content,
        array &$terminals,
        array &$blocks,
        array &$busbars,
        ?int $railWidth,
    ): void {
        $rails = $this->listOrViolation($run, $content->rails, 'content.rails');
        if (\count($rails) > self::MAX_RAILS) {
            $run->add('content.rails', \sprintf('At most %d rails are allowed.', self::MAX_RAILS));
        }

        foreach ($rails as $i => $rail) {
            $path = "content.rails[$i]";
            $run->claimId($rail->id, "$path.id");
            $run->decimalRange($rail->spacingMm, 0.0, false, DinRail::MAX_SPACING_MM, DinRail::SPACING_SCALE, "$path.spacingMm", $rail->id);
            $limit = $railWidth;

            /** @var list<array{int, int, string, string}> $spans offset, end, item path, item id */
            $spans = [];
            $geometryOk = null !== $limit && self::isList($rail->elements) && self::isList($rail->terminalBlocks);
            foreach ($this->listOrViolation($run, $rail->elements, "$path.elements") as $j => $element) {
                $itemPath = "$path.elements[$j]";
                $run->claimId($element->id, "$itemPath.id");
                $this->validateElement($run, $element, $itemPath);
                $terminals[$element->id] = ElementKind::Ats === $element->kind
                    ? [self::ATS_INPUTS, self::ATS_OUTPUTS]
                    : [\count($element->poles ?? []), \count($element->poles ?? [])];
                $geometryOk = $this->collectSpan($run, $spans, $limit, $element->id, $element->offsetModules, $element->widthModules, $itemPath, 'Does not fit within the rail width.') && $geometryOk;
            }
            foreach ($this->listOrViolation($run, $rail->terminalBlocks, "$path.terminalBlocks") as $j => $block) {
                $itemPath = "$path.terminalBlocks[$j]";
                $run->claimId($block->id, "$itemPath.id");
                $this->validateRailBlock($run, $block, $itemPath);
                $blocks[$block->id] = [$block->terminalCount, false];
                $geometryOk = $this->collectSpan($run, $spans, $limit, $block->id, $block->offsetModules, $block->widthModules, $itemPath, 'Does not fit within the rail width.') && $geometryOk;
            }

            $geometryOk = $this->checkOverlaps($run, $spans, 'Overlaps another item on the rail.') && $geometryOk;

            $this->validateBusbars($run, $rail, $path, $geometryOk, $busbars);
        }
    }

    /**
     * @param array<string, int> $busbars       busbar id => feed count (registered even when the busbar is invalid)
     * @param bool               $railItemsSane whether the rail has no offset/width/fit/overlap violations (the coverage walk is meaningful)
     */
    private function validateBusbars(ValidationRun $run, RailDto $rail, string $path, bool $railItemsSane, array &$busbars): void
    {
        $list = $this->listOrViolation($run, $rail->busbars, "$path.busbars");
        if (\count($list) > self::MAX_BUSBARS_PER_RAIL) {
            $run->add("$path.busbars", \sprintf('At most %d busbars per rail are allowed.', self::MAX_BUSBARS_PER_RAIL));

            // Over the cap the payload is rejected anyway: skip every per-busbar check (and the id
            // registration) so the cost stays bounded. Wires referencing these busbars then also
            // report "unknown busbar", which is acceptable noise on an already rejected payload.
            return;
        }

        /** @var array<string, ElementDto> $anchors element id => element (first occurrence), O(1) anchor lookup */
        $anchors = [];
        foreach ($rail->elements as $element) {
            $anchors[$element->id] ??= $element;
        }
        $items = $railItemsSane ? BusbarCoverage::railItems($rail) : [];

        /** @var array<string, true> $covered device ids covered by an earlier busbar */
        $covered = [];
        foreach ($list as $k => $busbar) {
            $busbarPath = "$path.busbars[$k]";
            $run->claimId($busbar->id, "$busbarPath.id");
            $busbars[$busbar->id] = $busbar->type->feedCount();

            $countOk = $run->intRange($busbar->count, self::MIN_BUSBAR_COUNT, self::MAX_BUSBAR_COUNT, "$busbarPath.count", $busbar->id);

            $anchor = $anchors[$busbar->firstElementId] ?? null;
            if (null === $anchor) {
                $run->add("$busbarPath.firstElementId", 'Must refer to a device on the same rail.', $busbar->id);

                continue;
            }
            if (ElementKind::Device !== $anchor->kind) {
                $run->add("$busbarPath.firstElementId", 'Must refer to a device, not an ATS.', $busbar->id);

                continue;
            }
            if (!$countOk || !$railItemsSane) {
                continue;
            }

            $coverage = BusbarCoverage::walk($items, $busbar);
            match ($coverage->reason) {
                BusbarCoverageReason::None, BusbarCoverageReason::AnchorMissing => null,
                BusbarCoverageReason::TooFew => $run->add("$busbarPath.count", \sprintf('Fewer than %d consecutive devices from the anchor to the rail end.', $busbar->count), $busbar->id),
                BusbarCoverageReason::ForeignItem => $run->add("$busbarPath.count", 'The covered items must all be devices (an ATS or a terminal block is in the way).', $busbar->id),
                BusbarCoverageReason::Gap => $run->add("$busbarPath.count", 'The covered devices must be adjacent (no free modules between them).', $busbar->id),
                BusbarCoverageReason::MixedSides => $run->add($busbarPath, 'All covered devices must have the same sidesSwapped.', $busbar->id),
            };

            if (array_any($coverage->devices, static fn(ElementDto $d): bool => isset($covered[$d->id]))) {
                $run->add("$busbarPath.firstElementId", 'Covers a device that another busbar already covers.', $busbar->id);
            }
            foreach ($coverage->devices as $device) {
                $covered[$device->id] = true;
            }
        }
    }

    private function validateElement(ValidationRun $run, ElementDto $element, string $path): void
    {
        $run->maxLength($element->label, self::MAX_LABEL_LENGTH, "$path.label", $element->id);
        $run->maxLength($element->marking, self::MAX_MARKING_LENGTH, "$path.marking", $element->id);

        if (ElementKind::Ats === $element->kind) {
            if (null === $element->state) {
                $run->add("$path.state", 'Required for an ATS.', $element->id);
            }
            if (null !== $element->on) {
                $run->add("$path.on", 'Must be null for an ATS.', $element->id);
            }
            if (null !== $element->poles) {
                $run->add("$path.poles", 'Must be null for an ATS.', $element->id);
            }
            if (null !== $element->style) {
                $run->add("$path.style", 'Must be null for an ATS.', $element->id);
            }
            if ($element->widthModules >= 1 && $element->widthModules < self::ATS_MIN_WIDTH) {
                $run->add("$path.widthModules", \sprintf('An ATS is at least %d modules wide.', self::ATS_MIN_WIDTH), $element->id);
            }

            return;
        }

        if (null !== $element->state) {
            $run->add("$path.state", 'Must be null for a device.', $element->id);
        }
        if (null === $element->on) {
            $run->add("$path.on", 'Required for a device.', $element->id);
        }
        if (null === $element->style) {
            $run->add("$path.style", 'Required for a device.', $element->id);
        }
        if (null === $element->poles) {
            $run->add("$path.poles", 'Required for a device.', $element->id);

            return;
        }
        if (!self::isList($element->poles)) {
            $run->add("$path.poles", 'Must be a JSON array (list), not an object.', $element->id);

            return;
        }
        $poles = \count($element->poles);
        $maxPoles = max(1, min($element->widthModules, Panel::MAX_RAIL_WIDTH_MODULES) * self::MAX_POLES_PER_MODULE);
        if ($poles < 1 || $poles > $maxPoles) {
            $run->add(
                "$path.poles",
                \sprintf('An element has 1 to %d poles (at most %d per module of width).', $maxPoles, self::MAX_POLES_PER_MODULE),
                $element->id,
            );
        }
    }

    private function validateRailBlock(ValidationRun $run, TerminalBlockDto $block, string $path): void
    {
        $run->maxLength($block->label, self::MAX_LABEL_LENGTH, "$path.label", $block->id);
        $run->intRange($block->terminalCount, 1, self::MAX_RAIL_BLOCK_TERMINALS, "$path.terminalCount", $block->id);
        if ($block->widthModules >= 1 && 1 !== $block->widthModules) {
            $run->add("$path.widthModules", 'A terminal block on a rail is exactly 1 module wide.', $block->id);
        }
    }

    /**
     * @param list<array{int, int, string, string}> $spans
     * @param int|null                              $limit width of the container, null when it is invalid itself
     *
     * @return bool false when the span has an offset/width/fit violation
     */
    private function collectSpan(
        ValidationRun $run,
        array &$spans,
        ?int $limit,
        string $id,
        int $offset,
        int $width,
        string $path,
        string $fitMessage,
    ): bool {
        $valid = true;
        if ($offset < 0) {
            $run->add("$path.offsetModules", 'Must not be negative.', $id);
            $valid = false;
        }
        if ($width < 1) {
            $run->add("$path.widthModules", 'Must be at least 1.', $id);
            $valid = false;
        }
        $fits = !($valid && null !== $limit && $offset + $width > $limit);
        if (!$fits) {
            $run->add("$path.offsetModules", $fitMessage, $id);
        }
        if ($valid) {
            $spans[] = [$offset, $offset + $width, $path, $id];
        }

        return $valid && $fits;
    }

    /**
     * @param list<array{int, int, string, string}> $spans
     *
     * @return bool false when some span overlaps another
     */
    private function checkOverlaps(ValidationRun $run, array $spans, string $message): bool
    {
        $clean = true;
        usort($spans, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $previousEnd = 0;
        foreach ($spans as [$offset, $end, $path, $id]) {
            if ($offset < $previousEnd) {
                $run->add("$path.offsetModules", $message, $id);
                $clean = false;
            }
            $previousEnd = max($previousEnd, $end);
        }

        return $clean;
    }

    /**
     * @param array<string, true>             $cableWires
     * @param array<string, array{int, int}>  $terminals
     * @param array<string, array{int, bool}> $blocks
     * @param array<string, int>              $busbars
     */
    private function validateWires(
        ValidationRun $run,
        PanelContent $content,
        array $cableWires,
        array $terminals,
        array $blocks,
        array $busbars,
    ): void {
        $wires = $this->listOrViolation($run, $content->wires, 'content.wires');
        if (\count($wires) > self::MAX_WIRES) {
            $run->add('content.wires', \sprintf('At most %d wires are allowed.', self::MAX_WIRES));
        }

        $totalBends = 0;
        foreach ($wires as $i => $wire) {
            $path = "content.wires[$i]";
            $run->claimId($wire->id, "$path.id");

            $startOk = $this->validateEndpoint($run, $wire->start, "$path.start", $wire->id, $cableWires, $terminals, $blocks, $busbars);
            $endOk = $this->validateEndpoint($run, $wire->end, "$path.end", $wire->id, $cableWires, $terminals, $blocks, $busbars);
            if ($startOk && $endOk && $wire->start->key() === $wire->end->key()) {
                $run->add("$path.end", 'The two ends of a wire must differ.', $wire->id);
            }

            $totalBends += $this->validateBends($run, $wire, $path);
        }

        if ($totalBends > self::MAX_TOTAL_BENDS) {
            $run->add('content.wires', \sprintf('At most %d bends per panel are allowed.', self::MAX_TOTAL_BENDS));
        }
    }

    /** @return int number of bends of the wire (0 when the bends are not a list) */
    private function validateBends(ValidationRun $run, WireDto $wire, string $path): int
    {
        if (!self::isList($wire->bends)) {
            $run->add("$path.bends", 'Must be a JSON array (list), not an object.', $wire->id);

            return 0;
        }
        if (\count($wire->bends) > self::MAX_BENDS_PER_WIRE) {
            $run->add("$path.bends", \sprintf('At most %d bends per wire are allowed.', self::MAX_BENDS_PER_WIRE), $wire->id);
        }
        foreach ($wire->bends as $k => $bend) {
            $run->decimalRange($bend->x, 0.0, false, PanelWireBend::MAX_COORDINATE_MM, PanelWireBend::SCALE, "$path.bends[$k].x", $wire->id);
            $run->decimalRange($bend->y, 0.0, false, PanelWireBend::MAX_COORDINATE_MM, PanelWireBend::SCALE, "$path.bends[$k].y", $wire->id);
        }

        return \count($wire->bends);
    }

    /**
     * The serializer keeps JSON object keys for list properties; such a payload is a 422 on its path
     * (and is then treated as empty so that nothing below it is validated).
     *
     * @template T
     *
     * @param array<array-key, T> $items
     *
     * @return list<T>
     */
    private function listOrViolation(ValidationRun $run, array $items, string $path): array
    {
        if (self::isList($items)) {
            return array_values($items);
        }
        $run->add($path, 'Must be a JSON array (list), not an object.');

        return [];
    }

    /**
     * @param array<string, true>             $cableWires
     * @param array<string, array{int, int}>  $terminals
     * @param array<string, array{int, bool}> $blocks
     * @param array<string, int>              $busbars
     */
    private function validateEndpoint(
        ValidationRun $run,
        EndpointDto $endpoint,
        string $path,
        string $wireId,
        array $cableWires,
        array $terminals,
        array $blocks,
        array $busbars,
    ): bool {
        $wellFormed = match ($endpoint->kind) {
            EndpointKind::Pole => null !== $endpoint->elementId && null !== $endpoint->poleIndex && null !== $endpoint->side
                && null === $endpoint->terminalBlockId && null === $endpoint->terminalIndex && null === $endpoint->cableWireId
                && null === $endpoint->contactSide && null === $endpoint->busbarId && null === $endpoint->feedIndex,
            EndpointKind::Terminal => null !== $endpoint->terminalBlockId && null !== $endpoint->terminalIndex && null !== $endpoint->contactSide
                && null === $endpoint->elementId && null === $endpoint->poleIndex && null === $endpoint->side && null === $endpoint->cableWireId && null === $endpoint->busbarId && null === $endpoint->feedIndex,
            EndpointKind::CableWire => null !== $endpoint->cableWireId
                && null === $endpoint->elementId && null === $endpoint->poleIndex && null === $endpoint->side
                && null === $endpoint->terminalBlockId && null === $endpoint->terminalIndex && null === $endpoint->contactSide
                && null === $endpoint->busbarId && null === $endpoint->feedIndex,
            EndpointKind::Busbar => null !== $endpoint->busbarId && null !== $endpoint->feedIndex
                && null === $endpoint->elementId && null === $endpoint->poleIndex && null === $endpoint->side
                && null === $endpoint->terminalBlockId && null === $endpoint->terminalIndex && null === $endpoint->contactSide
                && null === $endpoint->cableWireId,
        };
        if (!$wellFormed) {
            $run->add($path, \sprintf(
                'A "%s" endpoint needs exactly: %s.',
                $endpoint->kind->value,
                match ($endpoint->kind) {
                    EndpointKind::Pole => 'elementId, poleIndex and side',
                    EndpointKind::Terminal => 'terminalBlockId, terminalIndex and contactSide',
                    EndpointKind::CableWire => 'cableWireId',
                    EndpointKind::Busbar => 'busbarId and feedIndex',
                },
            ), $wireId);

            return false;
        }

        switch ($endpoint->kind) {
            case EndpointKind::Pole:
                $counts = $terminals[(string) $endpoint->elementId] ?? null;
                if (null === $counts) {
                    $run->add($path, 'Refers to an element that is not part of this content.', $wireId);

                    return false;
                }
                $poles = TerminalSide::Input === $endpoint->side ? $counts[0] : $counts[1];
                if ((int) $endpoint->poleIndex < 0 || (int) $endpoint->poleIndex >= $poles) {
                    $run->add("$path.poleIndex", \sprintf('Must be between 0 and %d.', $poles - 1), $wireId);

                    return false;
                }

                return true;
            case EndpointKind::Terminal:
                $block = $blocks[(string) $endpoint->terminalBlockId] ?? null;
                if (null === $block) {
                    $run->add($path, 'Refers to a terminal block that is not part of this content.', $wireId);

                    return false;
                }
                [$count, $inRow] = $block;
                $ok = true;
                if ((int) $endpoint->terminalIndex < 0 || (int) $endpoint->terminalIndex >= $count) {
                    $run->add("$path.terminalIndex", \sprintf('Must be between 0 and %d.', $count - 1), $wireId);
                    $ok = false;
                }
                $sideOk = $inRow
                    ? ContactSide::Top === $endpoint->contactSide || ContactSide::Bottom === $endpoint->contactSide
                    : ContactSide::Left === $endpoint->contactSide || ContactSide::Right === $endpoint->contactSide;
                if (!$sideOk) {
                    $run->add(
                        "$path.contactSide",
                        $inRow
                            ? 'Must be "top" or "bottom" for a terminal-row block.'
                            : 'Must be "left" or "right" for a terminal block on a rail.',
                        $wireId,
                    );
                    $ok = false;
                }

                return $ok;
            case EndpointKind::Busbar:
                $feeds = $busbars[(string) $endpoint->busbarId] ?? null;
                if (null === $feeds) {
                    $run->add($path, 'Refers to a busbar that is not part of this content.', $wireId);

                    return false;
                }
                if ((int) $endpoint->feedIndex < 0 || (int) $endpoint->feedIndex >= $feeds) {
                    $run->add("$path.feedIndex", \sprintf('Must be between 0 and %d.', $feeds - 1), $wireId);

                    return false;
                }

                return true;
            case EndpointKind::CableWire:
                if (!isset($cableWires[(string) $endpoint->cableWireId])) {
                    $run->add($path, 'Refers to a cable wire that is not part of this content.', $wireId);

                    return false;
                }

                return true;
        }
    }

    /**
     * Declared lists are not lists at runtime when the client sent a JSON object; the indirection
     * keeps static analysis from treating the check as always true.
     *
     * @param array<array-key, mixed> $items
     */
    private static function isList(array $items): bool
    {
        return array_is_list($items);
    }
}
