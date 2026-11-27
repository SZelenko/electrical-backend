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
                $c->append('rails', B::rail(3000 + $i, 10));
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
            $c->set('rails.0.widthModules', 0);
        }), 'content.rails[0].widthModules', B::uuid(30)];
        yield 'rail width 61' => [self::mutated(static function (Doc $c): void {
            $c->set('rails.0.widthModules', 61);
        }), 'content.rails[0].widthModules', B::uuid(30)];
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
            $c->set('rails.0.widthModules', 4);
        }), 'content.rails[0].terminalBlocks[0].offsetModules', B::uuid(32)];
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
            $c->append('rails', B::rail(30, 10));
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
            $c->set('rails.0.widthModules', 60);
            for ($i = 1; $i < 8; ++$i) {
                $c->append('rails', B::rail(3000 + $i, 60, [B::element(4000 + $i, 0, 60, 1)]));
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
            $c->set('rails.0.widthModules', 6);
            $c->set('rails.0.terminalBlocks.0.offsetModules', 2);
            $c->append('rails.0.elements', B::element(33, 3, 3, 1));
        });
        yield 'terminal row with 60 one-terminal blocks' => self::mutatedCase(static function (Doc $c): void {
            $c->set('terminalRow', array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i, 1), range(0, 59)));
        });
        yield 'row block of 60 terminals filling the row' => self::mutatedCase(static function (Doc $c): void {
            $c->set('terminalRow', [B::rowBlock(50, 0, 60, 'red', 'Bus')]);
        });
        yield 'row blocks touching' => self::mutatedCase(static function (Doc $c): void {
            $c->set('terminalRow', [B::rowBlock(50, 0, 4), B::rowBlock(51, 2, 3)]);
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
