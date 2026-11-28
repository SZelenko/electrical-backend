<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Panel\AtsState;
use App\Entity\Panel\CableSide;
use App\Entity\Panel\BusbarType;
use App\Entity\Panel\ContactSide;
use App\Entity\Panel\DeviceStyle;
use App\Entity\Panel\ElementKind;
use App\Entity\Panel\EndpointKind;
use App\Entity\Panel\TerminalSide;
use App\Entity\Panel\WireColour;
use App\Entity\Panel\WireCrossSection;
use App\Panel\Content\BendDto;
use App\Panel\Content\BusbarDto;
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

/**
 * Test helper: builds panel content payloads (plain arrays, exactly the JSON of
 * `PUT /panels/{id}/content`) and hydrates them into the domain DTOs.
 */
/**
 * @phpstan-import-type Content from PanelShapes
 * @phpstan-import-type CableWire from PanelShapes
 * @phpstan-import-type InputCable from PanelShapes
 * @phpstan-import-type OutputCable from PanelShapes
 * @phpstan-import-type Rail from PanelShapes
 * @phpstan-import-type Element from PanelShapes
 * @phpstan-import-type Block from PanelShapes
 * @phpstan-import-type Busbar from PanelShapes
 * @phpstan-import-type Wire from PanelShapes
 * @phpstan-import-type Endpoint from PanelShapes
 * @phpstan-import-type Bend from PanelShapes
 */
final class PanelContentBuilder
{
    /** Deterministic RFC 4122 v4 UUID for a test id: `uuid(1)` => 00000000-0000-4000-8000-000000000001. */
    public static function uuid(int $n): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    /**
     * @param list<string> $wireIds
     *
     * @return InputCable
     */
    public static function inputCable(int $id, array $wireIds = [], float $voltage = 230, float $frequency = 50, float $crossSection = 4, string $label = ''): array
    {
        $wireIds = [] === $wireIds ? [self::uuid($id + 1)] : $wireIds;

        return [
            'id' => self::uuid($id),
            'label' => $label,
            'voltage' => $voltage,
            'frequency' => $frequency,
            'crossSection' => $crossSection,
            'wires' => array_map(static fn(string $w): array => ['id' => $w, 'colour' => 'brown'], $wireIds),
        ];
    }

    /**
     * @param list<string> $wireIds
     *
     * @return OutputCable
     */
    public static function outputCable(int $id, array $wireIds = [], string $description = 'Lights', string $label = ''): array
    {
        $wireIds = [] === $wireIds ? [self::uuid($id + 1)] : $wireIds;

        return [
            'id' => self::uuid($id),
            'label' => $label,
            'description' => $description,
            'wires' => array_map(static fn(string $w): array => ['id' => $w, 'colour' => 'blue'], $wireIds),
        ];
    }

    /**
     * @param list<Element> $elements
     * @param list<Block>   $blocks
     * @param list<Busbar>  $busbars
     *
     * @return Rail
     */
    public static function rail(int $id, array $elements = [], array $blocks = [], float $spacing = 0, array $busbars = []): array
    {
        return ['id' => self::uuid($id), 'spacingMm' => $spacing, 'elements' => $elements, 'terminalBlocks' => $blocks, 'busbars' => $busbars];
    }

    /**
     * A busbar anchored at the device `$firstElementId`, covering `$count` devices.
     *
     * @return Busbar
     */
    public static function busbar(int $id, int $firstElementId, int $count = 2, string $type = 'L', ?string $colour = null): array
    {
        return ['id' => self::uuid($id), 'type' => $type, 'colour' => $colour, 'firstElementId' => self::uuid($firstElementId), 'count' => $count];
    }

    /** @return Endpoint */
    public static function busbarEnd(int $busbarId, int $feedIndex = 0): array
    {
        return ['kind' => 'busbar', 'busbarId' => self::uuid($busbarId), 'feedIndex' => $feedIndex];
    }

    /**
     * A device element (kind `device`).
     *
     * @return Element
     */
    public static function element(int $id, int $offset = 0, int $width = 1, int $poles = 1, bool $on = true, string $label = 'Q1', string $marking = 'A', string $style = 'breaker'): array
    {
        return [
            'kind' => 'device',
            'id' => self::uuid($id),
            'offsetModules' => $offset,
            'widthModules' => $width,
            'label' => $label,
            'marking' => $marking,
            'sidesSwapped' => false,
            'on' => $on,
            'poles' => array_fill(0, $poles, ['transit' => false]),
            'state' => null,
            'style' => $style,
        ];
    }

    /**
     * An ATS (kind `ats`; `on` and `poles` null).
     *
     * @return Element
     */
    public static function ats(int $id, int $offset = 0, int $width = 2, string $state = 'A', string $label = 'ATS', string $marking = 'AB'): array
    {
        return [
            'kind' => 'ats',
            'id' => self::uuid($id),
            'offsetModules' => $offset,
            'widthModules' => $width,
            'label' => $label,
            'marking' => $marking,
            'sidesSwapped' => false,
            'on' => null,
            'poles' => null,
            'state' => $state,
            'style' => null,
        ];
    }

    /**
     * A terminal block on a rail (1 module wide by default).
     *
     * @return Block
     */
    public static function block(int $id, int $offset = 0, int $width = 1, int $terminals = 2, ?string $colour = null, string $label = ''): array
    {
        return ['id' => self::uuid($id), 'offsetModules' => $offset, 'widthModules' => $width, 'terminalCount' => $terminals, 'colour' => $colour, 'label' => $label];
    }

    /**
     * A terminal-row block; the width is ceil(terminals / 2).
     *
     * @return Block
     */
    public static function rowBlock(int $id, int $offset = 0, int $terminals = 2, ?string $colour = null, string $label = ''): array
    {
        return self::block($id, $offset, intdiv($terminals + 1, 2), $terminals, $colour, $label);
    }

    /**
     * @param Endpoint   $start
     * @param Endpoint   $end
     * @param list<Bend> $bends
     *
     * @return Wire
     */
    public static function wire(int $id, array $start, array $end, array $bends = []): array
    {
        return ['id' => self::uuid($id), 'colour' => 'brown', 'crossSection' => '2.5', 'start' => $start, 'end' => $end, 'bends' => $bends];
    }

    /** @return Endpoint */
    public static function cableWireEnd(int $cableWireId): array
    {
        return ['kind' => 'cableWire', 'cableWireId' => self::uuid($cableWireId), 'contactSide' => null];
    }

    /** @return Endpoint */
    public static function poleEnd(int $elementId, int $poleIndex = 0, string $side = 'input'): array
    {
        return ['kind' => 'pole', 'elementId' => self::uuid($elementId), 'poleIndex' => $poleIndex, 'side' => $side, 'contactSide' => null];
    }

    /** @return Endpoint */
    public static function terminalEnd(int $blockId, int $index = 0, string $contactSide = 'left'): array
    {
        return ['kind' => 'terminal', 'terminalBlockId' => self::uuid($blockId), 'terminalIndex' => $index, 'contactSide' => $contactSide];
    }

    /**
     * A small valid content: one input cable (3 wires), one output cable, one rail with an element
     * (2 poles) and a terminal block, three wires. Ids 1..99.
     *
     * @return Content
     */
    public static function sample(): array
    {
        return [
            'railWidthModules' => 24,
            'inputCableSide' => 'top',
            'outputCableSide' => 'bottom',
            'inputCables' => [self::inputCable(10, [self::uuid(11), self::uuid(12), self::uuid(13)])],
            'outputCables' => [self::outputCable(20)],
            'terminalRow' => [],
            'bottomTerminalRow' => [],
            'rails' => [
                self::rail(30, [self::element(31, 0, 2, 2)], [self::block(32, 4, 1, 4)], 12.5),
            ],
            'wires' => [
                self::wire(40, self::cableWireEnd(11), self::poleEnd(31, 0, 'input')),
                self::wire(41, self::poleEnd(31, 0, 'output'), self::terminalEnd(32, 0), [['x' => 10.5, 'y' => 20], ['x' => 30, 'y' => 20.5]]),
                self::wire(42, self::terminalEnd(32, 1), self::cableWireEnd(21)),
            ],
        ];
    }

    /**
     * The content with dotted-path edits applied (`rails.0.elements.0.label`; a `[]` suffix appends to a list).
     *
     * @param Content              $content
     * @param array<string, mixed> $changes
     *
     * @return Content
     */
    public static function with(array $content, array $changes): array
    {
        $doc = new Doc($content);
        foreach ($changes as $path => $value) {
            if (str_ends_with($path, '[]')) {
                $doc->append(substr($path, 0, -2), $value);
            } else {
                $doc->set($path, $value);
            }
        }

        return $doc->content();
    }

    /**
     * The minimal valid content: one input cable with one wire, nothing else.
     *
     * @return Content
     */
    public static function minimal(): array
    {
        return [
            'railWidthModules' => 24,
            'inputCableSide' => 'top',
            'outputCableSide' => 'bottom',
            'inputCables' => [self::inputCable(10)],
            'outputCables' => [],
            'terminalRow' => [],
            'bottomTerminalRow' => [],
            'rails' => [],
            'wires' => [],
        ];
    }

    /**
     * The content with both cable sides set (`top`/`bottom`/`left`/`right`).
     *
     * @param Content $content
     *
     * @return Content
     */
    public static function withSides(array $content, string $input, string $output): array
    {
        $content['inputCableSide'] = $input;
        $content['outputCableSide'] = $output;

        return $content;
    }

    /**
     * The content with the bottom terminal row replaced by the given blocks.
     *
     * @param Content     $content
     * @param list<Block> $blocks
     *
     * @return Content
     */
    public static function withBottomRow(array $content, array $blocks): array
    {
        $content['bottomTerminalRow'] = $blocks;

        return $content;
    }

    /** @param Content $data */
    public static function toContent(array $data): PanelContent
    {
        $inputs = [];
        foreach ($data['inputCables'] as $c) {
            $inputs[] = new InputCableDto($c['id'], $c['label'], (float) $c['voltage'], (float) $c['frequency'], (float) $c['crossSection'], self::cableWires($c['wires']));
        }
        $outputs = [];
        foreach ($data['outputCables'] as $c) {
            $outputs[] = new OutputCableDto($c['id'], $c['label'], $c['description'], self::cableWires($c['wires']));
        }
        $rails = [];
        foreach ($data['rails'] as $r) {
            $elements = [];
            foreach ($r['elements'] as $e) {
                $poles = null;
                if (null !== $e['poles']) {
                    $poles = [];
                    foreach ($e['poles'] as $p) {
                        $poles[] = new PoleDto($p['transit']);
                    }
                }
                $elements[] = new ElementDto(
                    ElementKind::from($e['kind']),
                    $e['id'],
                    $e['offsetModules'],
                    $e['widthModules'],
                    $e['label'],
                    $e['marking'],
                    $e['sidesSwapped'],
                    $e['on'],
                    $poles,
                    null === $e['state'] ? null : AtsState::from($e['state']),
                    null === $e['style'] ? null : DeviceStyle::from($e['style']),
                );
            }
            $blocks = [];
            foreach ($r['terminalBlocks'] as $b) {
                $blocks[] = self::blockDto($b);
            }
            $busbars = [];
            foreach ($r['busbars'] as $bb) {
                $busbars[] = new BusbarDto($bb['id'], BusbarType::from($bb['type']), null === $bb['colour'] ? null : WireColour::from($bb['colour']), $bb['firstElementId'], $bb['count']);
            }
            $rails[] = new RailDto($r['id'], (float) $r['spacingMm'], $elements, $blocks, $busbars);
        }
        $row = [];
        foreach ($data['terminalRow'] as $b) {
            $row[] = self::blockDto($b);
        }
        $bottomRow = [];
        foreach ($data['bottomTerminalRow'] as $b) {
            $bottomRow[] = self::blockDto($b);
        }
        $wires = [];
        foreach ($data['wires'] as $w) {
            $bends = [];
            foreach ($w['bends'] as $b) {
                $bends[] = new BendDto((float) $b['x'], (float) $b['y']);
            }
            $wires[] = new WireDto(
                $w['id'],
                WireColour::from($w['colour']),
                WireCrossSection::from($w['crossSection']),
                self::endpoint($w['start']),
                self::endpoint($w['end']),
                $bends,
            );
        }

        return new PanelContent(
            $data['railWidthModules'],
            CableSide::from($data['inputCableSide']),
            CableSide::from($data['outputCableSide']),
            $inputs,
            $outputs,
            $row,
            $bottomRow,
            $rails,
            $wires,
        );
    }

    /**
     * @param list<CableWire> $wires
     *
     * @return list<CableWireDto>
     */
    private static function cableWires(array $wires): array
    {
        $result = [];
        foreach ($wires as $w) {
            $result[] = new CableWireDto($w['id'], WireColour::from($w['colour']));
        }

        return $result;
    }

    /** @param Block $b */
    private static function blockDto(array $b): TerminalBlockDto
    {
        return new TerminalBlockDto(
            $b['id'],
            $b['offsetModules'],
            $b['widthModules'],
            $b['terminalCount'],
            null === $b['colour'] ? null : WireColour::from($b['colour']),
            $b['label'],
        );
    }

    /** @param Endpoint $e */
    private static function endpoint(array $e): EndpointDto
    {
        return new EndpointDto(
            EndpointKind::from($e['kind']),
            $e['elementId'] ?? null,
            $e['poleIndex'] ?? null,
            isset($e['side']) ? TerminalSide::from($e['side']) : null,
            $e['terminalBlockId'] ?? null,
            $e['terminalIndex'] ?? null,
            $e['cableWireId'] ?? null,
            isset($e['contactSide']) ? ContactSide::from($e['contactSide']) : null,
            $e['busbarId'] ?? null,
            $e['feedIndex'] ?? null,
        );
    }
}
