<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Support\PanelContentBuilder as B;

/**
 * Tables of valid and invalid panel contents, shared by the unit test of the validator and the
 * functional test of `PUT /panels/{id}/content`. Every mutator takes `B::sample()` (see there for
 * the ids: input cable 10 with wires 11-13, output cable 20 with wire 21, rail 30 (24 modules) with
 * element 31 (offset 0, width 2, 2 poles) and block 32 (offset 4, width 2, 4 terminals), wires 40-42).
 */
/** @phpstan-import-type Content from PanelShapes */
final class PanelContentCases
{
    /**
     * Invalid contents: name => [content, expected propertyPath, expected item id or null].
     *
     * @return iterable<string, array{Content, string, ?string}>
     */
    public static function invalid(): iterable
    {
        // --- required / caps (R4)
        yield 'no input cable' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables', []);
            $c->set('wires', []);
        }), 'content.inputCables', null];
        yield '21 input cables' => [self::mutated(static function (Doc $c): void {
            for ($i = 0; $i < 20; ++$i) {
                $c->append('inputCables', B::inputCable(1000 + 2 * $i));
            }
        }), 'content.inputCables', null];
        yield '101 output cables' => [self::mutated(static function (Doc $c): void {
            for ($i = 0; $i < 100; ++$i) {
                $c->append('outputCables', B::outputCable(2000 + 2 * $i));
            }
        }), 'content.outputCables', null];
        yield '9 rails' => [self::mutated(static function (Doc $c): void {
            for ($i = 0; $i < 8; ++$i) {
                $c->append('rails', B::rail(3000 + $i));
            }
        }), 'content.rails', null];
        yield '5001 wires' => [self::mutated(static function (Doc $c): void {
            $c->set('wires', []);
            for ($i = 0; $i < 5001; ++$i) {
                $c->append('wires', B::wire(10000 + $i, B::cableWireEnd(11), B::cableWireEnd(12)));
            }
        }), 'content.wires', null];
        yield '51 bends' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.bends', array_fill(0, 51, ['x' => 1, 'y' => 1]));
        }), 'content.wires[0].bends', B::uuid(40)];

        // --- cables
        yield 'input cable without wires' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.wires', []);
            $c->set('wires', []);
        }), 'content.inputCables[0].wires', null];
        yield 'input cable with 5 wires' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.wires', array_map(static fn(int $i): array => ['id' => B::uuid($i), 'colour' => 'red'], [11, 12, 13, 14, 15]));
        }), 'content.inputCables[0].wires', null];
        yield 'output cable without wires' => [self::mutated(static function (Doc $c): void {
            $c->set('outputCables.0.wires', []);
            $c->set('wires.2.end', B::cableWireEnd(12));
        }), 'content.outputCables[0].wires', null];
        yield 'output cable with 5 wires' => [self::mutated(static function (Doc $c): void {
            $c->set('outputCables.0.wires', array_map(static fn(int $i): array => ['id' => B::uuid($i), 'colour' => 'red'], [21, 22, 23, 24, 25]));
        }), 'content.outputCables[0].wires', null];
        yield 'output description 256 characters' => [self::mutated(static function (Doc $c): void {
            $c->set('outputCables.0.description', str_repeat('x', 256));
        }), 'content.outputCables[0].description', B::uuid(20)];
        yield 'output description 256 code points' => [self::mutated(static function (Doc $c): void {
            $c->set('outputCables.0.description', str_repeat('щ', 256));
        }), 'content.outputCables[0].description', B::uuid(20)];

        yield 'input cable label 17 characters' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.label', str_repeat('x', 17));
        }), 'content.inputCables[0].label', B::uuid(10)];
        yield 'input cable label 17 code points (2-byte)' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.label', str_repeat('щ', 17));
        }), 'content.inputCables[0].label', B::uuid(10)];
        yield 'input cable label 17 code points (4-byte)' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.label', str_repeat('😀', 17));
        }), 'content.inputCables[0].label', B::uuid(10)];
        yield 'output cable label 17 characters' => [self::mutated(static function (Doc $c): void {
            $c->set('outputCables.0.label', str_repeat('x', 17));
        }), 'content.outputCables[0].label', B::uuid(20)];
        yield 'output cable label 17 code points (2-byte)' => [self::mutated(static function (Doc $c): void {
            $c->set('outputCables.0.label', str_repeat('щ', 17));
        }), 'content.outputCables[0].label', B::uuid(20)];

        // --- numbers and scale (R5)
        foreach ([
            'voltage 0' => ['voltage', 0],
            'voltage above 99999.9' => ['voltage', 100000],
            'voltage negative' => ['voltage', -230],
            'voltage with 2 decimals (not rounded)' => ['voltage', 230.55],
            'frequency negative' => ['frequency', -1],
            'frequency above 999.99' => ['frequency', 1000],
            'frequency with 3 decimals' => ['frequency', 50.123],
            'cable cross-section 0' => ['crossSection', 0],
            'cable cross-section above 999.99' => ['crossSection', 1000],
            'cable cross-section with 3 decimals' => ['crossSection', 4.001],
        ] as $name => [$field, $value]) {
            yield $name => [self::mutated(static function (Doc $c) use ($field, $value): void {
                $c->set('inputCables.0.' . $field, $value);
            }), 'content.inputCables[0].' . $field, B::uuid(10)];
        }
        foreach ([
            'rail spacing negative' => -1,
            'rail spacing above 99999.9' => 100000,
            'rail spacing with 2 decimals' => 12.25,
        ] as $name => $value) {
            yield $name => [self::mutated(static function (Doc $c) use ($value): void {
                $c->set('rails.0.spacingMm', $value);
            }), 'content.rails[0].spacingMm', B::uuid(30)];
        }
        foreach ([
            'bend x negative' => ['x', -0.1],
            'bend x above 99999.9' => ['x', 100000],
            'bend x with 2 decimals' => ['x', 1.25],
            'bend y negative' => ['y', -1],
            'bend y above 99999.9' => ['y', 99999.95],
            'bend y with 2 decimals' => ['y', 0.05],
        ] as $name => [$axis, $value]) {
            yield $name => [self::mutated(static function (Doc $c) use ($axis, $value): void {
                $c->set('wires.1.bends.0.' . $axis, $value);
            }), 'content.wires[1].bends[0].' . $axis, B::uuid(41)];
        }

        yield 'voltage 1e-8' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.voltage', 1e-8);
        }), 'content.inputCables[0].voltage', B::uuid(10)];
        yield 'voltage 230.00000001' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.voltage', 230.00000001);
        }), 'content.inputCables[0].voltage', B::uuid(10)];
        yield 'rail spacing 0.05' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.spacingMm', 0.05);
        }), 'content.rails[0].spacingMm', B::uuid(30)];
        yield 'more than 20000 bends in total' => [self::mutated(static function (Doc $c): void {
            $wires = [];
            for ($i = 0; $i < 401; ++$i) {
                $wires[] = B::wire(20000 + $i, B::cableWireEnd(11), B::cableWireEnd(12), array_fill(0, 401 === $i + 1 ? 1 : 50, ['x' => 1, 'y' => 1]));
            }
            $c->set('wires', $wires);
        }), 'content.wires', null];

        // --- rails, rail width, placement
        yield 'rail width 0' => [self::mutated(static function (Doc $c): void {
            $c->set('railWidthModules', 0);
        }), 'content.railWidthModules', null];
        yield 'rail width 61' => [self::mutated(static function (Doc $c): void {
            $c->set('railWidthModules', 61);
        }), 'content.railWidthModules', null];
        yield 'rail width negative' => [self::mutated(static function (Doc $c): void {
            $c->set('railWidthModules', -24);
        }), 'content.railWidthModules', null];
        yield 'element overlaps element' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::element(33, 1, 1));
        }), 'content.rails[0].elements[1].offsetModules', B::uuid(33)];
        yield 'element overlaps block (element after)' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::element(33, 4, 2));
        }), 'content.rails[0].elements[1].offsetModules', B::uuid(33)];
        yield 'block overlaps element (block after)' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::element(33, 3, 2));
        }), 'content.rails[0].terminalBlocks[0].offsetModules', B::uuid(32)];
        yield 'block overlaps block' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.terminalBlocks', B::block(33, 4, 1, 2));
        }), 'content.rails[0].terminalBlocks[1].offsetModules', B::uuid(33)];
        yield 'identical span' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::element(33, 0, 2, 2));
        }), 'content.rails[0].elements[1].offsetModules', B::uuid(33)];
        yield 'element does not fit the rail' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::element(33, 23, 2));
        }), 'content.rails[0].elements[1].offsetModules', B::uuid(33)];
        yield 'block does not fit the rail' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.offsetModules', 24);
        }), 'content.rails[0].terminalBlocks[0].offsetModules', B::uuid(32)];
        yield 'block does not fit the shrunk rail' => [self::mutated(static function (Doc $c): void {
            $c->set('railWidthModules', 4);
        }), 'content.rails[0].terminalBlocks[0].offsetModules', B::uuid(32)];
        yield 'element does not fit the shrunk rail (offset + width = width + 1)' => [self::mutated(static function (Doc $c): void {
            $c->set('railWidthModules', 6);
            $c->append('rails.0.elements', B::element(33, 5, 2));
        }), 'content.rails[0].elements[1].offsetModules', B::uuid(33)];

        yield 'element negative offset' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.offsetModules', -1);
        }), 'content.rails[0].elements[0].offsetModules', B::uuid(31)];
        yield 'element width 0' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.widthModules', 0);
        }), 'content.rails[0].elements[0].widthModules', B::uuid(31)];
        yield 'block width 0' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.widthModules', 0);
        }), 'content.rails[0].terminalBlocks[0].widthModules', B::uuid(32)];

        // --- density (poles per module, terminals per module)
        yield '5 poles on 1 module' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.widthModules', 1);
            $c->set('rails.0.elements.0.poles', array_fill(0, 5, ['transit' => false]));
        }), 'content.rails[0].elements[0].poles', B::uuid(31)];
        yield '9 poles on 2 modules' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.poles', array_fill(0, 9, ['transit' => false]));
        }), 'content.rails[0].elements[0].poles', B::uuid(31)];
        yield 'element without poles' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.poles', []);
            $c->set('wires', []);
        }), 'content.rails[0].elements[0].poles', B::uuid(31)];
        yield 'rail block with 13 terminals' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.terminalCount', 13);
        }), 'content.rails[0].terminalBlocks[0].terminalCount', B::uuid(32)];
        yield 'rail block 2 modules wide' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.widthModules', 2);
        }), 'content.rails[0].terminalBlocks[0].widthModules', B::uuid(32)];
        yield 'block without terminals' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.terminalCount', 0);
            $c->set('wires', []);
        }), 'content.rails[0].terminalBlocks[0].terminalCount', B::uuid(32)];

        // --- text limits (Unicode code points)
        yield 'element label 17 characters' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.label', str_repeat('x', 17));
        }), 'content.rails[0].elements[0].label', B::uuid(31)];
        yield 'element label 17 code points (2-byte)' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.label', str_repeat('щ', 17));
        }), 'content.rails[0].elements[0].label', B::uuid(31)];
        yield 'element marking 7 characters' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.marking', str_repeat('x', 7));
        }), 'content.rails[0].elements[0].marking', B::uuid(31)];

        // --- wire endpoints (R1, R7)
        yield 'pole index beyond the poles' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.end.poleIndex', 2);
        }), 'content.wires[0].end.poleIndex', B::uuid(40)];
        yield 'negative pole index' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.end.poleIndex', -1);
        }), 'content.wires[0].end.poleIndex', B::uuid(40)];
        yield 'terminal index beyond the terminals' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.end.terminalIndex', 4);
        }), 'content.wires[1].end.terminalIndex', B::uuid(41)];
        yield 'negative terminal index' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.end.terminalIndex', -1);
        }), 'content.wires[1].end.terminalIndex', B::uuid(41)];
        yield 'unknown element id' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.end.elementId', B::uuid(999));
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'unknown terminal block id' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.end.terminalBlockId', B::uuid(999));
        }), 'content.wires[1].end', B::uuid(41)];
        yield 'unknown cable wire id' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.start.cableWireId', B::uuid(999));
        }), 'content.wires[0].start', B::uuid(40)];
        yield 'cable id instead of cable wire id' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.start.cableWireId', B::uuid(10));
        }), 'content.wires[0].start', B::uuid(40)];
        yield 'pole endpoint without side' => [self::mutated(static function (Doc $c): void {
            $c->unset('wires.0.end.side');
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'terminal endpoint with a stray element id' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.end.elementId', B::uuid(31));
        }), 'content.wires[1].end', B::uuid(41)];
        yield 'self-loop on a cable wire' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.end', B::cableWireEnd(11));
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'self-loop on a pole terminal' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.start', B::poleEnd(31, 1, 'output'));
            $c->set('wires.0.end', B::poleEnd(31, 1, 'output'));
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'self-loop on a block terminal' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.start', B::terminalEnd(32, 2));
            $c->set('wires.1.end', B::terminalEnd(32, 2));
        }), 'content.wires[1].end', B::uuid(41)];

        // --- terminal row (stage 3)
        yield '61 terminal-row blocks' => [self::mutated(static function (Doc $c): void {
            $c->set('terminalRow', array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i, 1), range(0, 60)));
        }), 'content.terminalRow', null];
        yield 'row block without terminals' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::block(50, 0, 1, 0));
        }), 'content.terminalRow[0].terminalCount', B::uuid(50)];
        yield 'row block with 61 terminals' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::block(50, 0, 31, 61));
        }), 'content.terminalRow[0].terminalCount', B::uuid(50)];
        yield 'row block wrong width (5 terminals on 2 modules)' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::block(50, 0, 2, 5));
        }), 'content.terminalRow[0].widthModules', B::uuid(50)];
        yield 'row block wrong width (2 terminals on 2 modules)' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::block(50, 0, 2, 2));
        }), 'content.terminalRow[0].widthModules', B::uuid(50)];
        yield 'row block negative offset' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, -1, 2));
        }), 'content.terminalRow[0].offsetModules', B::uuid(50)];
        yield 'row block does not fit the row' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 59, 4));
        }), 'content.terminalRow[0].offsetModules', B::uuid(50)];
        // the capacity of a row is the rail width (24 in the sample), whatever the input cables
        yield 'row block starting at the rail width' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 24, 2));
        }), 'content.terminalRow[0].offsetModules', B::uuid(50)];
        yield 'row block ends one module past the rail width' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 23, 4));
        }), 'content.terminalRow[0].offsetModules', B::uuid(50)];
        yield 'many input-cable wires do not shrink the row capacity (block past the rail width)' => [self::mutated(static function (Doc $c): void {
            $c->set('inputCables.0.wires', array_map(static fn(int $i): array => ['id' => B::uuid($i), 'colour' => 'brown'], [11, 12, 13, 14]));
            $c->append('terminalRow', B::rowBlock(50, 21, 8));
        }), 'content.terminalRow[0].offsetModules', B::uuid(50)];
        yield 'bottom row block ends past the rail width' => [self::mutated(static function (Doc $c): void {
            $c->append('bottomTerminalRow', B::rowBlock(50, 23, 4));
        }), 'content.bottomTerminalRow[0].offsetModules', B::uuid(50)];
        yield 'bottom row blocks overlap' => [self::mutated(static function (Doc $c): void {
            $c->append('bottomTerminalRow', B::rowBlock(50, 0, 4));
            $c->append('bottomTerminalRow', B::rowBlock(51, 1, 2));
        }), 'content.bottomTerminalRow[1].offsetModules', B::uuid(51)];
        yield 'bottom row block id equals a top row block id' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 2));
            $c->append('bottomTerminalRow', B::rowBlock(50, 5, 2));
        }), 'content.bottomTerminalRow[0].id', B::uuid(50)];
        yield 'bottom row has 61 blocks' => [self::mutated(static function (Doc $c): void {
            $c->set('railWidthModules', 60);
            $c->set('bottomTerminalRow', array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i % 60, 1), range(0, 60)));
        }), 'content.bottomTerminalRow', null];
        yield 'row block with width 0 in the bottom row' => [self::mutated(static function (Doc $c): void {
            $c->append('bottomTerminalRow', B::block(50, 0, 0, 2));
        }), 'content.bottomTerminalRow[0].widthModules', B::uuid(50)];
        yield 'row blocks overlap' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('terminalRow', B::rowBlock(51, 1, 2));
        }), 'content.terminalRow[1].offsetModules', B::uuid(51)];
        yield 'row block label 17 characters' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 2, null, str_repeat('x', 17)));
        }), 'content.terminalRow[0].label', B::uuid(50)];
        yield 'rail id equals a row block id (row is claimed first)' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(30, 0, 2));
        }), 'content.rails[0].id', B::uuid(30)];
        yield 'rail block label 17 characters' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.label', str_repeat('x', 17));
        }), 'content.rails[0].terminalBlocks[0].label', B::uuid(32)];

        // --- ATS and device kinds (stage 3)
        yield 'ATS width 1' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 10, 1));
        }), 'content.rails[0].elements[1].widthModules', B::uuid(33)];
        yield 'ATS with poles' => [self::mutated(static function (Doc $c): void {
            $ats = B::ats(33, 10, 2);
            $ats['poles'] = [['transit' => false]];
            $c->append('rails.0.elements', $ats);
        }), 'content.rails[0].elements[1].poles', B::uuid(33)];
        yield 'ATS with on' => [self::mutated(static function (Doc $c): void {
            $ats = B::ats(33, 10, 2);
            $ats['on'] = true;
            $c->append('rails.0.elements', $ats);
        }), 'content.rails[0].elements[1].on', B::uuid(33)];
        yield 'ATS without state' => [self::mutated(static function (Doc $c): void {
            $ats = B::ats(33, 10, 2);
            $ats['state'] = null;
            $c->append('rails.0.elements', $ats);
        }), 'content.rails[0].elements[1].state', B::uuid(33)];
        yield 'ATS label 17 characters' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 10, 2, 'A', str_repeat('x', 17)));
        }), 'content.rails[0].elements[1].label', B::uuid(33)];
        yield 'ATS marking 7 characters' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 10, 2, 'A', 'ATS', str_repeat('x', 7)));
        }), 'content.rails[0].elements[1].marking', B::uuid(33)];
        yield 'ATS overlaps an element' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 1, 2));
        }), 'content.rails[0].elements[1].offsetModules', B::uuid(33)];
        yield 'ATS does not fit the rail' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 23, 2));
        }), 'content.rails[0].elements[1].offsetModules', B::uuid(33)];
        yield 'device with a state' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.state', 'A');
        }), 'content.rails[0].elements[0].state', B::uuid(31)];
        yield 'device without on' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.on', null);
        }), 'content.rails[0].elements[0].on', B::uuid(31)];
        yield 'device without poles (null)' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.poles', null);
            $c->set('wires', []);
        }), 'content.rails[0].elements[0].poles', B::uuid(31)];
        yield 'device without style' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.elements.0.style', null);
        }), 'content.rails[0].elements[0].style', B::uuid(31)];
        yield 'ATS with a style' => [self::mutated(static function (Doc $c): void {
            $ats = B::ats(33, 10, 2);
            $ats['style'] = 'rcd';
            $c->append('rails.0.elements', $ats);
        }), 'content.rails[0].elements[1].style', B::uuid(33)];
        yield 'ATS output pole index 2' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 10, 2));
            $c->append('wires', B::wire(50, B::cableWireEnd(11), B::poleEnd(33, 2, 'output')));
        }), 'content.wires[3].end.poleIndex', B::uuid(50)];
        yield 'ATS input pole index 4' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 10, 2));
            $c->append('wires', B::wire(50, B::cableWireEnd(11), B::poleEnd(33, 4, 'input')));
        }), 'content.wires[3].end.poleIndex', B::uuid(50)];

        // --- contact sides (stage 3)
        yield 'contact side top on a rail block' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.end.contactSide', 'top');
        }), 'content.wires[1].end.contactSide', B::uuid(41)];
        yield 'contact side bottom on a rail block' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.end.contactSide', 'bottom');
        }), 'content.wires[1].end.contactSide', B::uuid(41)];
        yield 'contact side left on a row block' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('wires', B::wire(51, B::cableWireEnd(11), B::terminalEnd(50, 0, 'left')));
        }), 'content.wires[3].end.contactSide', B::uuid(51)];
        yield 'contact side right on a row block' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('wires', B::wire(51, B::cableWireEnd(11), B::terminalEnd(50, 0, 'right')));
        }), 'content.wires[3].end.contactSide', B::uuid(51)];
        yield 'terminal endpoint without a contact side' => [self::mutated(static function (Doc $c): void {
            $c->unset('wires.1.end.contactSide');
        }), 'content.wires[1].end', B::uuid(41)];
        yield 'pole endpoint with a contact side' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.end.contactSide', 'left');
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'cable wire endpoint with a contact side' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.0.start.contactSide', 'top');
        }), 'content.wires[0].start', B::uuid(40)];
        yield 'row block contact top to bottom is one node' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('wires', B::wire(51, B::terminalEnd(50, 1, 'top'), B::terminalEnd(50, 1, 'bottom')));
        }), 'content.wires[3].end', B::uuid(51)];
        yield 'rail block contact left to right is one node' => [self::mutated(static function (Doc $c): void {
            $c->append('wires', B::wire(51, B::terminalEnd(32, 1, 'left'), B::terminalEnd(32, 1, 'right')));
        }), 'content.wires[3].end', B::uuid(51)];
        yield 'row block terminal index beyond the terminals' => [self::mutated(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('wires', B::wire(51, B::cableWireEnd(11), B::terminalEnd(50, 4, 'top')));
        }), 'content.wires[3].end.terminalIndex', B::uuid(51)];

        // --- busbars (stage 5)
        yield 'busbar count 1' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 1)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield 'busbar count 0' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 0)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield 'busbar count 61' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 61)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield '31 busbars on a rail' => [self::mutated(static function (Doc $c): void {
            $devices = [];
            $busbars = [];
            for ($i = 0; $i < 60; ++$i) {
                $devices[] = B::element(7000 + $i, $i, 1);
            }
            for ($i = 0; $i < 30; ++$i) {
                $busbars[] = B::busbar(8000 + $i, 7000 + 2 * $i, 2);
            }
            $busbars[] = B::busbar(8030, 7000, 2);
            $c->set('railWidthModules', 60);
            $c->set('rails.0.elements', $devices);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('rails.0.busbars', $busbars);
            $c->set('wires', []);
        }), 'content.rails[0].busbars', null];
        yield 'busbar anchor unknown' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 999, 2)]);
        }), 'content.rails[0].busbars[0].firstElementId', B::uuid(60)];
        yield 'busbar anchor is a terminal block' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 32, 2)]);
        }), 'content.rails[0].busbars[0].firstElementId', B::uuid(60)];
        yield 'busbar anchor on another rail' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->append('rails', B::rail(70, [B::element(71, 0, 1), B::element(72, 1, 1)]));
            $c->set('rails.0.busbars', [B::busbar(60, 71, 2)]);
        }), 'content.rails[0].busbars[0].firstElementId', B::uuid(60)];
        yield 'busbar anchor is an ATS' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->append('rails.0.elements', B::ats(37, 13, 2));
            $c->set('rails.0.busbars', [B::busbar(60, 37, 2)]);
        }), 'content.rails[0].busbars[0].firstElementId', B::uuid(60)];
        yield 'busbar runs past the last item of the rail' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', [B::wire(40, B::cableWireEnd(11), B::poleEnd(33, 0, 'input'))]);
            $c->set('rails.0.busbars', [B::busbar(60, 36, 2)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield 'busbar count beyond the devices from the anchor' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 5)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield 'busbar covers an ATS' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.terminalBlocks.0.offsetModules', 8);
            $c->append('rails.0.elements', B::ats(37, 4, 2));
            $c->set('rails.0.busbars', [B::busbar(60, 33, 5)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield 'busbar covers a terminal block' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 5)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield 'busbar over devices with a free module between them' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.elements.3.offsetModules', 4);
            $c->set('rails.0.terminalBlocks.0.offsetModules', 6);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 4)]);
        }), 'content.rails[0].busbars[0].count', B::uuid(60)];
        yield 'busbar over devices with mixed sidesSwapped' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.elements.1.sidesSwapped', true);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 2)]);
        }), 'content.rails[0].busbars[0]', B::uuid(60)];
        yield 'two busbars cover the same device (partial overlap)' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 2), B::busbar(61, 34, 2)]);
        }), 'content.rails[0].busbars[1].firstElementId', B::uuid(61)];
        yield 'two busbars with the same anchor' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 2), B::busbar(61, 33, 2)]);
        }), 'content.rails[0].busbars[1].firstElementId', B::uuid(61)];
        yield 'busbar id equals an element id' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(33, 35, 2)]);
        }), 'content.rails[0].busbars[0].id', B::uuid(33)];
        yield 'duplicate busbar id' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 2), B::busbar(60, 35, 2)]);
        }), 'content.rails[0].busbars[1].id', B::uuid(60)];
        yield 'busbar id is not a uuid' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $busbar = B::busbar(60, 33, 2);
            $busbar['id'] = 'not-a-uuid';
            $c->set('rails.0.busbars', [$busbar]);
        }), 'content.rails[0].busbars[0].id', null];
        yield 'busbar anchor id is not a uuid' => [self::mutated(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $busbar = B::busbar(60, 33, 2);
            $busbar['firstElementId'] = 'not-a-uuid';
            $c->set('rails.0.busbars', [$busbar]);
        }), 'content.rails[0].busbars[0].firstElementId', B::uuid(60)];

        // --- busbar endpoints (stage 5)
        yield 'busbar endpoint with an unknown busbar id' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::busbarEnd(999, 0));
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'busbar endpoint with an element id instead of the busbar id' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::busbarEnd(33, 0));
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'L busbar feed index 1' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::busbarEnd(60, 1));
        }), 'content.wires[0].end.feedIndex', B::uuid(40)];
        yield 'LN busbar feed index 2' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c, 'LN');
            $c->set('wires.0.end', B::busbarEnd(60, 2));
        }), 'content.wires[0].end.feedIndex', B::uuid(40)];
        yield 'busbar feed index negative' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::busbarEnd(60, -1));
        }), 'content.wires[0].end.feedIndex', B::uuid(40)];
        yield 'busbar endpoint without a feed index' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', ['kind' => 'busbar', 'busbarId' => B::uuid(60)]);
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'busbar endpoint without a busbar id' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', ['kind' => 'busbar', 'feedIndex' => 0]);
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'busbar endpoint with a stray element id' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::busbarEnd(60, 0) + ['elementId' => B::uuid(33)]);
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'busbar endpoint with a stray contact side' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::busbarEnd(60, 0) + ['contactSide' => 'top']);
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'pole endpoint with a stray busbar id' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::poleEnd(33, 0, 'input') + ['busbarId' => B::uuid(60)]);
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'cable wire endpoint with a stray feed index' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.end', B::cableWireEnd(12) + ['feedIndex' => 0]);
        }), 'content.wires[0].end', B::uuid(40)];
        yield 'self-loop on a busbar feed' => [self::mutated(static function (Doc $c): void {
            self::withBusbar($c);
            $c->set('wires.0.start', B::busbarEnd(60, 0));
            $c->set('wires.0.end', B::busbarEnd(60, 0));
        }), 'content.wires[0].end', B::uuid(40)];

        // --- ids (UUID format, nil, duplicates)
        foreach ([
            'input cable id' => ['inputCables.0.id', 'content.inputCables[0].id'],
            'cable wire id' => ['inputCables.0.wires.0.id', 'content.inputCables[0].wires[0].id'],
            'rail id' => ['rails.0.id', 'content.rails[0].id'],
            'element id' => ['rails.0.elements.0.id', 'content.rails[0].elements[0].id'],
            'block id' => ['rails.0.terminalBlocks.0.id', 'content.rails[0].terminalBlocks[0].id'],
            'wire id' => ['wires.0.id', 'content.wires[0].id'],
        ] as $label => [$docPath, $path]) {
            foreach (['not a uuid' => 'not-a-uuid', 'nil uuid' => '00000000-0000-0000-0000-000000000000', 'empty' => '', 'uppercase' => 'ABCDEF01-0000-4000-8000-000000000077', 'wrong variant' => '00000000-0000-4000-c000-000000000077'] as $kind => $bad) {
                yield "$label: $kind" => [self::mutated(static function (Doc $c) use ($docPath, $bad): void {
                    $c->set($docPath, $bad);
                }), $path, null];
            }
        }
        yield 'duplicate rail id' => [self::mutated(static function (Doc $c): void {
            $c->append('rails', B::rail(30));
        }), 'content.rails[1].id', B::uuid(30)];
        yield 'element id equals a cable wire id' => [self::mutated(static function (Doc $c): void {
            $c->append('rails.0.elements', B::element(11, 10, 1));
        }), 'content.rails[0].elements[1].id', B::uuid(11)];
        yield 'duplicate wire id' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.1.id', B::uuid(40));
        }), 'content.wires[1].id', B::uuid(40)];
        yield 'wire id equals the rail id' => [self::mutated(static function (Doc $c): void {
            $c->set('wires.2.id', B::uuid(30));
        }), 'content.wires[2].id', B::uuid(30)];
    }

    /**
     * Valid contents on the limits: name => content.
     *
     * @return iterable<string, array{Content}>
     */
    public static function validBoundaries(): iterable
    {
        yield 'minimal' => [B::minimal()];
        yield 'sample' => [B::sample()];
        yield 'max numbers' => self::mutatedCase(static function (Doc $c): void {
            $c->set('inputCables.0.voltage', 99999.9);
            $c->set('inputCables.0.frequency', 999.99);
            $c->set('inputCables.0.crossSection', 999.99);
            $c->set('rails.0.spacingMm', 99999.9);
            $c->set('wires.1.bends', [['x' => 99999.9, 'y' => 99999.9]]);
        });
        yield 'min numbers' => self::mutatedCase(static function (Doc $c): void {
            $c->set('inputCables.0.voltage', 0.1);
            $c->set('inputCables.0.frequency', 0);
            $c->set('inputCables.0.crossSection', 0.01);
            $c->set('rails.0.spacingMm', 0);
            $c->set('wires.1.bends', [['x' => 0, 'y' => 0]]);
        });
        yield '50 bends' => self::mutatedCase(static function (Doc $c): void {
            $c->set('wires.0.bends', array_fill(0, 50, ['x' => 1.5, 'y' => 2.5]));
        });
        yield '4 wires per cable' => self::mutatedCase(static function (Doc $c): void {
            $c->set('inputCables.0.wires', array_map(static fn(int $i): array => ['id' => B::uuid($i), 'colour' => 'red'], [11, 12, 13, 14]));
            $c->set('outputCables.0.wires', array_map(static fn(int $i): array => ['id' => B::uuid($i), 'colour' => 'black'], [21, 22, 23, 24]));
        });
        yield '8 rails of 60 modules' => self::mutatedCase(static function (Doc $c): void {
            $c->set('railWidthModules', 60);
            for ($i = 1; $i < 8; ++$i) {
                $c->append('rails', B::rail(3000 + $i, [B::element(4000 + $i, 0, 60, 1)]));
            }
        });
        yield '4 poles on 1 module, 2 terminals on 1 module' => self::mutatedCase(static function (Doc $c): void {
            $c->set('rails.0.elements.0.widthModules', 1);
            $c->set('rails.0.elements.0.poles', array_fill(0, 4, ['transit' => true]));
            $c->set('rails.0.terminalBlocks.0.widthModules', 1);
            $c->set('rails.0.terminalBlocks.0.terminalCount', 2);
            $c->set('wires.1.end', B::terminalEnd(32, 1));
            $c->set('wires.2.start', B::terminalEnd(32, 0));
        });
        yield 'touching items filling the rail' => self::mutatedCase(static function (Doc $c): void {
            $c->set('railWidthModules', 6);
            $c->set('rails.0.terminalBlocks.0.offsetModules', 2);
            $c->append('rails.0.elements', B::element(33, 3, 3, 1));
        });
        yield 'each terminal row of 60 one-terminal blocks' => self::mutatedCase(static function (Doc $c): void {
            $c->set('railWidthModules', 60);
            $c->set('terminalRow', array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i, 1), range(0, 59)));
            $c->set('bottomTerminalRow', array_map(static fn(int $i): array => B::rowBlock(6000 + $i, $i, 1), range(0, 59)));
        });
        yield 'row block of 60 terminals filling the row' => self::mutatedCase(static function (Doc $c): void {
            // capacity = rail width = 30 modules = 60 terminals
            $c->set('railWidthModules', 30);
            $c->set('terminalRow', [B::rowBlock(50, 0, 60, 'red', 'Bus')]);
        });
        yield 'row block ending exactly at the rail width' => self::mutatedCase(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 22, 4));
        });
        yield 'row block starting at module 0 with many input-cable wires' => self::mutatedCase(static function (Doc $c): void {
            $c->set('inputCables.0.wires', array_map(static fn(int $i): array => ['id' => B::uuid($i), 'colour' => 'brown'], [11, 12, 13, 14]));
            $c->set('wires', []);
            $c->set('railWidthModules', 12);
            $c->set('rails.0.terminalBlocks.0.offsetModules', 4);
            $c->set('terminalRow', [B::rowBlock(50, 0, 24)]);
        });
        yield 'row capacity of exactly one module' => self::mutatedCase(static function (Doc $c): void {
            $c->set('railWidthModules', 1);
            $c->set('rails.0.elements', []);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', []);
            $c->set('terminalRow', [B::rowBlock(50, 0, 1)]);
            $c->set('bottomTerminalRow', [B::rowBlock(51, 0, 2)]);
        });
        yield 'row blocks touching' => self::mutatedCase(static function (Doc $c): void {
            $c->set('terminalRow', [B::rowBlock(50, 0, 4), B::rowBlock(51, 2, 3)]);
        });
        yield 'bottom row blocks touching' => self::mutatedCase(static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(50, 0, 4), B::rowBlock(51, 2, 3)]);
        });
        yield 'blocks at the same offset in both rows' => self::mutatedCase(static function (Doc $c): void {
            $c->set('terminalRow', [B::rowBlock(50, 0, 4)]);
            $c->set('bottomTerminalRow', [B::rowBlock(51, 0, 4)]);
        });
        yield 'every side combination: both kinds left' => self::mutatedCase(static function (Doc $c): void {
            $c->set('inputCableSide', 'left');
            $c->set('outputCableSide', 'left');
        });
        yield 'rail block of 12 terminals' => self::mutatedCase(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.terminalCount', 12);
        });
        yield 'coloured and labelled blocks' => self::mutatedCase(static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.colour', 'green-yellow');
            $c->set('rails.0.terminalBlocks.0.label', str_repeat('щ', 16));
        });
        yield 'ATS in each state' => self::mutatedCase(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 10, 2, 'A'));
            $c->append('rails.0.elements', B::ats(34, 12, 3, 'B'));
            $c->append('rails.0.elements', B::ats(35, 15, 2, 'off', '', ''));
        });
        yield 'ATS wired on all terminals' => self::mutatedCase(static function (Doc $c): void {
            $c->append('rails.0.elements', B::ats(33, 10, 2));
            foreach ([0, 1, 2, 3] as $i) {
                $c->append('wires', B::wire(60 + $i, B::cableWireEnd(11), B::poleEnd(33, $i, 'input')));
            }
            foreach ([0, 1] as $i) {
                $c->append('wires', B::wire(70 + $i, B::cableWireEnd(21), B::poleEnd(33, $i, 'output')));
            }
        });
        yield 'row block wired on both contact sides' => self::mutatedCase(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('wires', B::wire(51, B::cableWireEnd(11), B::terminalEnd(50, 0, 'top')));
            $c->append('wires', B::wire(52, B::terminalEnd(50, 1, 'bottom'), B::cableWireEnd(21)));
        });
        yield 'same row contact from top and from bottom in different wires' => self::mutatedCase(static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('wires', B::wire(51, B::cableWireEnd(11), B::terminalEnd(50, 0, 'top')));
            $c->append('wires', B::wire(52, B::terminalEnd(50, 0, 'bottom'), B::cableWireEnd(21)));
        });
        yield 'all device styles' => self::mutatedCase(static function (Doc $c): void {
            $c->append('rails.0.elements', B::element(33, 10, 1, 1, true, 'Q2', 'B', 'rcd'));
            $c->append('rails.0.elements', B::element(34, 12, 1, 1, false, 'Q3', 'C', 'voltageRelay'));
        });
        yield 'rail width 1 without items' => self::mutatedCase(static function (Doc $c): void {
            $c->set('railWidthModules', 1);
            $c->set('rails.0.elements', []);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', [B::wire(40, B::cableWireEnd(11), B::cableWireEnd(21))]);
        });
        yield 'rail width 60 with a full-width element' => self::mutatedCase(static function (Doc $c): void {
            $c->set('railWidthModules', 60);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', []);
            $c->set('rails.0.elements', [B::element(31, 0, 60, 4)]);
        });
        yield 'cable labels of 16 code points and empty' => self::mutatedCase(static function (Doc $c): void {
            $c->set('inputCables.0.label', str_repeat('😀', 16));
            $c->set('outputCables.0.label', str_repeat('щ', 16));
            $c->append('inputCables', B::inputCable(15, [B::uuid(16)], label: ''));
            $c->append('outputCables', B::outputCable(25, [B::uuid(26)], label: ''));
        });
        yield 'L busbar over two devices' => self::mutatedCase(static function (Doc $c): void {
            self::withBusbar($c);
        });
        yield 'LN busbar fed on both feeds' => self::mutatedCase(static function (Doc $c): void {
            self::withBusbar($c, 'LN');
            $c->set('wires.0.end', B::busbarEnd(60, 1));
        });
        yield 'busbar over devices and adjacent items after the covered set' => self::mutatedCase(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 3)]);
        });
        yield 'two disjoint busbars on one rail' => self::mutatedCase(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 2), B::busbar(61, 35, 2, 'LN')]);
        });
        yield 'busbar over devices that are all sidesSwapped' => self::mutatedCase(static function (Doc $c): void {
            self::withBusbarDevices($c);
            $c->set('rails.0.elements.0.sidesSwapped', true);
            $c->set('rails.0.elements.1.sidesSwapped', true);
            $c->set('rails.0.busbars', [B::busbar(60, 33, 2)]);
        });
        yield 'busbar of 60 devices' => self::mutatedCase(static function (Doc $c): void {
            $devices = [];
            for ($i = 0; $i < 60; ++$i) {
                $devices[] = B::element(7000 + $i, $i, 1);
            }
            $c->set('railWidthModules', 60);
            $c->set('rails.0.elements', $devices);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('rails.0.busbars', [B::busbar(60, 7000, 60, 'LN')]);
            $c->set('wires', []);
        });
        yield '30 busbars on a rail' => self::mutatedCase(static function (Doc $c): void {
            $devices = [];
            $busbars = [];
            for ($i = 0; $i < 30; ++$i) {
                $devices[] = B::element(7000 + 2 * $i, 2 * $i, 1);
                $devices[] = B::element(7001 + 2 * $i, 2 * $i + 1, 1);
                $busbars[] = B::busbar(8000 + $i, 7000 + 2 * $i, 2);
            }
            $c->set('railWidthModules', 60);
            $c->set('rails.0.elements', $devices);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('rails.0.busbars', $busbars);
            $c->set('wires', []);
        });
        yield 'wire from a busbar feed to a covered input' => self::mutatedCase(static function (Doc $c): void {
            self::withBusbar($c);
            $c->append('wires', B::wire(44, B::busbarEnd(60, 0), B::poleEnd(34, 0, 'input')));
        });
        yield 'text limits' => self::mutatedCase(static function (Doc $c): void {
            $c->set('rails.0.elements.0.label', str_repeat('щ', 16));
            $c->set('rails.0.elements.0.marking', str_repeat('😀', 6));
            $c->set('outputCables.0.description', str_repeat('я', 255));
        });
        yield 'empty texts' => self::mutatedCase(static function (Doc $c): void {
            $c->set('rails.0.elements.0.label', '');
            $c->set('rails.0.elements.0.marking', '');
            $c->set('outputCables.0.description', '');
        });
        yield 'wire from pole input to pole output of the same pole' => self::mutatedCase(static function (Doc $c): void {
            $c->set('wires.0', B::wire(40, B::poleEnd(31, 0, 'input'), B::poleEnd(31, 0, 'output')));
        });
        yield 'output cable wire as an endpoint' => self::mutatedCase(static function (Doc $c): void {
            $c->append('wires', B::wire(43, B::cableWireEnd(21), B::cableWireEnd(11)));
        });
        yield 'all colours and cross-sections' => self::mutatedCase(static function (Doc $c): void {
            $colours = ['black', 'brown', 'red', 'blue', 'green-yellow'];
            $sections = ['1', '1.5', '2.5', '4', '6'];
            $c->set('wires', []);
            foreach ($colours as $i => $colour) {
                $wire = B::wire(40 + $i, B::terminalEnd(32, $i % 4), B::cableWireEnd(11));
                $wire['colour'] = $colour;
                $wire['crossSection'] = $sections[$i];
                $c->append('wires', $wire);
            }
        });
    }

    /**
     * Replaces the rail 30 elements by the devices 33, 34, 35 and 36 (offsets 0 to 3, 1 module and 2
     * poles each); the block 32 stays at offset 4. No busbars; the wires 41-42 (block) stay valid, wire 40
     * (cable wire 11 -> element 31) is repointed to the device 33.
     */
    private static function withBusbarDevices(Doc $c): void
    {
        $c->set('rails.0.elements', [
            B::element(33, 0, 1, 2),
            B::element(34, 1, 1, 2),
            B::element(35, 2, 1, 2),
            B::element(36, 3, 1, 2),
        ]);
        $c->set('rails.0.terminalBlocks.0.offsetModules', 4);
        $c->set('wires.0.end', B::poleEnd(33, 0, 'input'));
        $c->set('wires.1.start', B::poleEnd(33, 0, 'output'));
    }

    /**
     * Devices 33 and 34 under busbar 60 (anchor 33, count 2, the given type), devices 35 and 36 uncovered;
     * the wire 40 goes from cable wire 11 to the feed 0 of the busbar.
     */
    private static function withBusbar(Doc $c, string $type = 'L'): void
    {
        self::withBusbarDevices($c);
        $c->set('rails.0.busbars', [B::busbar(60, 33, 2, $type)]);
        $c->set('wires.0.end', B::busbarEnd(60, 0));
    }

    /**
     * @param callable(Doc): void $mutator
     *
     * @return Content
     */
    private static function mutated(callable $mutator): array
    {
        $doc = new Doc(B::sample());
        $mutator($doc);

        return $doc->content();
    }

    /**
     * @param callable(Doc): void $mutator
     *
     * @return array{Content}
     */
    private static function mutatedCase(callable $mutator): array
    {
        $doc = new Doc(B::sample());
        $mutator($doc);

        return [$doc->content()];
    }
}
