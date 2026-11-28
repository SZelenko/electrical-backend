<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Tests\Support\Doc;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelShapes;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Stage 3 of `PUT /panels/{id}/content`: the terminal row, coloured and labelled terminal blocks,
 * the ATS element kind and the contact side of terminal endpoints.
 *
 * @phpstan-import-type Content from PanelShapes
 */
final class TerminalRowAndAtsTest extends PanelApiTestCase
{
    /**
     * Ids: input cable 10 (wires 11, 12, 13), output cable 20 (wire 21), row blocks 50 (4 contacts,
     * red, "Bus L") and 51 (1 contact, grey), rail 30 with device 31, ATSs 33 (A), 34 (B), 35 (off)
     * and rail block 32 (green-yellow, "PE").
     *
     * @return Content
     */
    private static function rich(): array
    {
        $doc = new Doc(B::sample());
        $doc->set('terminalRow', [B::rowBlock(50, 0, 4, 'red', 'Bus L'), B::rowBlock(51, 5, 1)]);
        $doc->set('rails.0.terminalBlocks.0.colour', 'green-yellow');
        $doc->set('rails.0.terminalBlocks.0.label', 'PE');
        $doc->append('rails.0.elements', B::ats(33, 6, 2, 'A', 'ATS A', 'AB'));
        $doc->append('rails.0.elements', B::ats(34, 9, 3, 'B', 'ATS B', ''));
        $doc->append('rails.0.elements', B::ats(35, 13, 2, 'off', '', ''));
        $doc->set('wires', [
            B::wire(40, B::cableWireEnd(11), B::terminalEnd(50, 0, 'top')),
            B::wire(41, B::terminalEnd(50, 3, 'bottom'), B::poleEnd(33, 0, 'input')),
            B::wire(42, B::poleEnd(33, 1, 'output'), B::terminalEnd(32, 2, 'right')),
            B::wire(43, B::terminalEnd(32, 0, 'left'), B::poleEnd(34, 3, 'input')),
            B::wire(44, B::poleEnd(35, 1, 'output'), B::cableWireEnd(21)),
        ]);

        return $doc->content();
    }

    public function testSaveRichContentRoundTripSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $put = $this->saveContent($user, $panel['id'], self::rich());
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        foreach ([$put, $get] as $body) {
            $content = $body['content'];
            self::assertSame([B::uuid(50), B::uuid(51)], array_column($content['terminalRow'], 'id'));
            self::assertSame('red', $content['terminalRow'][0]['colour']);
            self::assertSame('Bus L', $content['terminalRow'][0]['label']);
            self::assertSame(2, $content['terminalRow'][0]['widthModules']);
            self::assertNull($content['terminalRow'][1]['colour']);
            self::assertSame('', $content['terminalRow'][1]['label']);
            $block = $content['rails'][0]['terminalBlocks'][0];
            self::assertSame(['green-yellow', 'PE', 1], [$block['colour'], $block['label'], $block['widthModules']]);

            $elements = array_column($content['rails'][0]['elements'], null, 'id');
            self::assertSame('device', $elements[B::uuid(31)]['kind']);
            self::assertNull($elements[B::uuid(31)]['state']);
            foreach ([33 => 'A', 34 => 'B', 35 => 'off'] as $n => $state) {
                $ats = $elements[B::uuid($n)];
                self::assertSame('ats', $ats['kind']);
                self::assertSame($state, $ats['state']);
                // explicit nulls, not absent keys
                self::assertArrayHasKey('on', $ats);
                self::assertArrayHasKey('poles', $ats);
                self::assertNull($ats['on']);
                self::assertNull($ats['poles']);
            }
            self::assertSame(3, $elements[B::uuid(34)]['widthModules']);

            $wires = array_column($content['wires'], null, 'id');
            self::assertSame('top', $wires[B::uuid(40)]['end']['contactSide'] ?? 'absent');
            self::assertSame('bottom', $wires[B::uuid(41)]['start']['contactSide'] ?? 'absent');
            self::assertSame('right', $wires[B::uuid(42)]['end']['contactSide'] ?? 'absent');
            self::assertSame('left', $wires[B::uuid(43)]['start']['contactSide'] ?? 'absent');
            self::assertArrayHasKey('contactSide', $wires[B::uuid(40)]['start']);
            self::assertNull($wires[B::uuid(40)]['start']['contactSide'] ?? null);
            self::assertArrayHasKey('contactSide', $wires[B::uuid(41)]['end']);
            self::assertNull($wires[B::uuid(41)]['end']['contactSide'] ?? null);
            self::assertSame(2, $body['version']);
        }
    }

    public function testSaveRichContentPersistsColumnsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $this->saveContent($user, $panel['id'], self::rich());

        // Row blocks belong to the panel directly, rail blocks to a rail (and to the panel too).
        self::assertSame(3, $this->countRows('terminal_block', $panel['id']));
        self::assertSame(2, $this->sqlInt('SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND rail_id IS NULL', [$panel['id']]));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND rail_id IS NOT NULL', [$panel['id']]));
        self::assertSame(
            ['red', 'Bus L', 4, 2],
            array_values($this->connection()->fetchAssociative('SELECT colour, label, terminal_count, width_modules FROM terminal_block WHERE id = ?', [B::uuid(50)]) ?: []),
        );
        self::assertSame(
            ['green-yellow', 'PE'],
            array_values($this->connection()->fetchAssociative('SELECT colour, label FROM terminal_block WHERE id = ?', [B::uuid(32)]) ?: []),
        );
        self::assertNull($this->connection()->fetchOne('SELECT colour FROM terminal_block WHERE id = ?', [B::uuid(51)]));

        self::assertSame(['device', true, null], array_values($this->connection()->fetchAssociative('SELECT kind, is_on, ats_state FROM element WHERE id = ?', [B::uuid(31)]) ?: []));
        foreach ([33 => 'A', 34 => 'B', 35 => 'off'] as $n => $state) {
            self::assertSame(['ats', null, $state], array_values($this->connection()->fetchAssociative('SELECT kind, is_on, ats_state FROM element WHERE id = ?', [B::uuid($n)]) ?: []));
            self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM element_pole WHERE element_id = ?', [B::uuid($n)]), 'An ATS has no pole rows.');
        }

        self::assertSame(
            [null, 'top'],
            array_values($this->connection()->fetchAssociative('SELECT start_contact_side, end_contact_side FROM panel_wire WHERE id = ?', [B::uuid(40)]) ?: []),
        );
        self::assertSame(
            ['bottom', null],
            array_values($this->connection()->fetchAssociative('SELECT start_contact_side, end_contact_side FROM panel_wire WHERE id = ?', [B::uuid(41)]) ?: []),
        );
        self::assertSame(
            [null, 'right'],
            array_values($this->connection()->fetchAssociative('SELECT start_contact_side, end_contact_side FROM panel_wire WHERE id = ?', [B::uuid(42)]) ?: []),
        );
    }

    public function testSaveReplacesRowBlocksSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], self::rich());

        $next = B::sample();
        $next['terminalRow'] = [B::rowBlock(60, 10, 6, 'blue', 'New')];
        $body = $this->saveContent($user, $panel['id'], $next);

        self::assertSame([B::uuid(60)], array_column($body['content']['terminalRow'], 'id'));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND rail_id IS NULL', [$panel['id']]));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM terminal_block WHERE id IN (?, ?)', [B::uuid(50), B::uuid(51)]));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM element WHERE kind = ?', ['ats']));

        $emptied = $this->saveContent($user, $panel['id'], B::minimal());

        self::assertSame([], $emptied['content']['terminalRow']);
        self::assertSame(0, $this->countRows('terminal_block', $panel['id']));
    }

    public function testRowBlockIdReusedAfterReplaceSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::minimal();
        $content['terminalRow'] = [B::rowBlock(50, 0, 2)];
        $this->saveContent($user, $panel['id'], $content);
        $content['terminalRow'] = [B::rowBlock(50, 3, 2, 'black', 'again')];

        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertSame(3, $body['content']['terminalRow'][0]['offsetModules']);
        self::assertSame('again', $body['content']['terminalRow'][0]['label']);
    }

    public function testRowBlockIdOfAnotherUsersPanelError(): void
    {
        $owner = $this->createVerifiedUser();
        $first = $this->createPanel($owner);
        $content = B::minimal();
        $content['terminalRow'] = [B::rowBlock(50, 0, 2)];
        $this->saveContent($owner, $first['id'], $content);
        $other = $this->createVerifiedUser();
        $second = $this->createPanel($other);

        $response = $this->putContent($other, $second['id'], $content);

        $this->assertPanelProblem($response, 409, 'panel_content_id_conflict');
        self::assertSame(1, $this->panelRow($second['id'])?->version);
        self::assertSame(0, $this->countRows('terminal_block', $second['id']));
        self::assertSame(1, $this->countRows('terminal_block', $first['id']));
    }

    public function testAtsShortCircuitIsAWarningSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = [
            'railWidthModules' => 24,
            'inputCableSide' => 'top',
            'outputCableSide' => 'bottom',
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])],
            'outputCables' => [],
            'terminalRow' => [B::rowBlock(50, 0, 2)],
            'bottomTerminalRow' => [],
            'rails' => [B::rail(30, [B::ats(31, 0, 2, 'A')])],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::poleEnd(31, 0, 'input')),
                B::wire(41, B::cableWireEnd(12), B::terminalEnd(50, 0, 'top')),
                B::wire(42, B::poleEnd(31, 0, 'output'), B::terminalEnd(50, 1, 'bottom')),
            ],
        ];

        $response = $this->putContent($user, $panel['id'], $content);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $data = $this->panelBody($response);
        self::assertCount(1, $data['shortCircuits']);
        $group = $data['shortCircuits'][0];
        self::assertEqualsCanonicalizing([B::uuid(11), B::uuid(12)], $group['sources']);
        self::assertEqualsCanonicalizing([B::uuid(40), B::uuid(41), B::uuid(42)], $group['wireIds']);
        // Terminals are listed once per node and without the drawing-only contact side.
        $keys = array_map(static fn(array $t): string => ('terminal' === $t['kind'] ? 't:' . ($t['terminalBlockId'] ?? '') . ':' . ($t['terminalIndex'] ?? '') : $t['kind'] . ':' . ($t['elementId'] ?? $t['cableWireId'] ?? '') . ':' . ($t['poleIndex'] ?? '') . ':' . ($t['side'] ?? '')), $group['terminals']);
        self::assertSame($keys, array_values(array_unique($keys)));
        foreach ($group['terminals'] as $terminal) {
            self::assertNull($terminal['contactSide'] ?? null);
        }
        self::assertCount(1, $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user))['shortCircuits']);
    }

    public function testAtsOffAndSeparateLinesHaveNoShortCircuitSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = [
            'railWidthModules' => 24,
            'inputCableSide' => 'top',
            'outputCableSide' => 'bottom',
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])],
            'outputCables' => [B::outputCable(20, [B::uuid(21)])],
            'terminalRow' => [],
            'bottomTerminalRow' => [],
            'rails' => [B::rail(30, [B::ats(31, 0, 2, 'off')])],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::poleEnd(31, 0, 'input')),
                B::wire(41, B::cableWireEnd(12), B::poleEnd(31, 2, 'input')),
                B::wire(42, B::poleEnd(31, 0, 'output'), B::cableWireEnd(21)),
            ],
        ];

        self::assertSame([], $this->saveContent($user, $panel['id'], $content)['shortCircuits']);

        $content = B::with($content, ['rails.0.elements.0.state' => 'B']);
        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertSame([], $body['shortCircuits'], 'B joins out0 with in2 only: one source on the output.');

        $content = B::with($content, ['rails.0.elements.0.state' => 'A']);
        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertSame([], $body['shortCircuits'], 'A joins out0 with in0 only: one source on the output.');
    }

    /**
     * Exact messages of the new rules: name => [mutation of rich(), propertyPath, message, item id].
     *
     * @return iterable<string, array{callable(Doc): void, string, string, ?string}>
     */
    public static function ruleMessages(): iterable
    {
        yield 'row block width does not match the contacts' => [static function (Doc $c): void {
            $c->set('terminalRow.0.widthModules', 3);
        }, 'content.terminalRow[0].widthModules', 'Must be 2 (half of the terminal count, rounded up).', B::uuid(50)];
        yield 'row block without contacts' => [static function (Doc $c): void {
            $c->set('terminalRow.1.terminalCount', 0);
        }, 'content.terminalRow[1].terminalCount', 'Must be between 1 and 60.', B::uuid(51)];
        yield 'row block does not fit the row' => [static function (Doc $c): void {
            $c->set('terminalRow.1.offsetModules', 59);
            $c->set('terminalRow.1.terminalCount', 4);
            $c->set('terminalRow.1.widthModules', 2);
        }, 'content.terminalRow[1].offsetModules', 'Does not fit within the top terminal row (rail width).', B::uuid(51)];
        yield 'row blocks overlap' => [static function (Doc $c): void {
            $c->set('terminalRow.1.offsetModules', 1);
        }, 'content.terminalRow[1].offsetModules', 'Overlaps another block in the top terminal row.', B::uuid(51)];
        yield 'bottom row block does not fit the row' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(52, 23, 4)]);
        }, 'content.bottomTerminalRow[0].offsetModules', 'Does not fit within the bottom terminal row (rail width).', B::uuid(52)];
        yield 'bottom row blocks overlap' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(52, 0, 4), B::rowBlock(53, 1, 2)]);
        }, 'content.bottomTerminalRow[1].offsetModules', 'Overlaps another block in the bottom terminal row.', B::uuid(53)];
        yield 'bottom row block width does not match the contacts' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::block(52, 0, 3, 4)]);
        }, 'content.bottomTerminalRow[0].widthModules', 'Must be 2 (half of the terminal count, rounded up).', B::uuid(52)];
        yield 'bottom row block without contacts' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::block(52, 0, 1, 0)]);
        }, 'content.bottomTerminalRow[0].terminalCount', 'Must be between 1 and 60.', B::uuid(52)];
        yield 'bottom row block label too long' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(52, 0, 2, null, str_repeat('x', 17))]);
        }, 'content.bottomTerminalRow[0].label', 'Must be at most 16 characters long.', B::uuid(52)];
        yield 'bottom row block reuses a top row block id' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(50, 10, 2)]);
        }, 'content.bottomTerminalRow[0].id', 'Duplicate id within the payload.', B::uuid(50)];
        yield 'rail block reuses a bottom row block id (rows are claimed first)' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(32, 10, 2)]);
        }, 'content.rails[0].terminalBlocks[0].id', 'Duplicate id within the payload.', B::uuid(32)];
        yield 'contact side left on a bottom row block' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(52, 10, 2)]);
            $c->append('wires', B::wire(45, B::cableWireEnd(12), B::terminalEnd(52, 0, 'left')));
        }, 'content.wires[5].end.contactSide', 'Must be "top" or "bottom" for a terminal-row block.', B::uuid(45)];
        yield 'contact side right on a bottom row block' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(52, 10, 2)]);
            $c->append('wires', B::wire(45, B::cableWireEnd(12), B::terminalEnd(52, 0, 'right')));
        }, 'content.wires[5].end.contactSide', 'Must be "top" or "bottom" for a terminal-row block.', B::uuid(45)];
        yield 'row block label too long' => [static function (Doc $c): void {
            $c->set('terminalRow.0.label', str_repeat('x', 17));
        }, 'content.terminalRow[0].label', 'Must be at most 16 characters long.', B::uuid(50)];
        yield 'rail block wider than one module' => [static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.widthModules', 2);
        }, 'content.rails[0].terminalBlocks[0].widthModules', 'A terminal block on a rail is exactly 1 module wide.', B::uuid(32)];
        yield 'rail block with 13 contacts' => [static function (Doc $c): void {
            $c->set('rails.0.terminalBlocks.0.terminalCount', 13);
        }, 'content.rails[0].terminalBlocks[0].terminalCount', 'Must be between 1 and 12.', B::uuid(32)];
        yield 'ATS one module wide' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.widthModules', 1);
        }, 'content.rails[0].elements[1].widthModules', 'An ATS is at least 2 modules wide.', B::uuid(33)];
        yield 'ATS with poles' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.poles', [['transit' => false]]);
        }, 'content.rails[0].elements[1].poles', 'Must be null for an ATS.', B::uuid(33)];
        yield 'ATS with on' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.on', false);
        }, 'content.rails[0].elements[1].on', 'Must be null for an ATS.', B::uuid(33)];
        yield 'ATS without state' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.state', null);
        }, 'content.rails[0].elements[1].state', 'Required for an ATS.', B::uuid(33)];
        yield 'device with a state' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.state', 'B');
        }, 'content.rails[0].elements[0].state', 'Must be null for a device.', B::uuid(31)];
        yield 'device without on' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.on', null);
        }, 'content.rails[0].elements[0].on', 'Required for a device.', B::uuid(31)];
        yield 'device without poles' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.poles', null);
            $c->set('wires', []);
        }, 'content.rails[0].elements[0].poles', 'Required for a device.', B::uuid(31)];
        yield 'device without style' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.style', null);
        }, 'content.rails[0].elements[0].style', 'Required for a device.', B::uuid(31)];
        yield 'ATS with a style' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.style', 'breaker');
        }, 'content.rails[0].elements[1].style', 'Must be null for an ATS.', B::uuid(33)];
        yield 'ATS output pole index 2' => [static function (Doc $c): void {
            $c->set('wires.4.start', B::poleEnd(33, 2, 'output'));
        }, 'content.wires[4].start.poleIndex', 'Must be between 0 and 1.', B::uuid(44)];
        yield 'ATS input pole index 4' => [static function (Doc $c): void {
            $c->set('wires.1.end', B::poleEnd(33, 4, 'input'));
        }, 'content.wires[1].end.poleIndex', 'Must be between 0 and 3.', B::uuid(41)];
        yield 'contact side top on a rail block' => [static function (Doc $c): void {
            $c->set('wires.2.end.contactSide', 'top');
        }, 'content.wires[2].end.contactSide', 'Must be "left" or "right" for a terminal block on a rail.', B::uuid(42)];
        yield 'contact side left on a row block' => [static function (Doc $c): void {
            $c->set('wires.0.end.contactSide', 'left');
        }, 'content.wires[0].end.contactSide', 'Must be "top" or "bottom" for a terminal-row block.', B::uuid(40)];
        yield 'terminal endpoint without a contact side' => [static function (Doc $c): void {
            $c->unset('wires.0.end.contactSide');
        }, 'content.wires[0].end', 'A "terminal" endpoint needs exactly: terminalBlockId, terminalIndex and contactSide.', B::uuid(40)];
        yield 'pole endpoint with a contact side' => [static function (Doc $c): void {
            $c->set('wires.1.end.contactSide', 'top');
        }, 'content.wires[1].end', 'A "pole" endpoint needs exactly: elementId, poleIndex and side.', B::uuid(41)];
        yield 'row contact from top to bottom' => [static function (Doc $c): void {
            $c->set('wires.0', B::wire(40, B::terminalEnd(50, 1, 'top'), B::terminalEnd(50, 1, 'bottom')));
        }, 'content.wires[0].end', 'The two ends of a wire must differ.', B::uuid(40)];
        yield 'row block contact beyond the contacts' => [static function (Doc $c): void {
            $c->set('wires.0.end.terminalIndex', 4);
        }, 'content.wires[0].end.terminalIndex', 'Must be between 0 and 3.', B::uuid(40)];
    }

    /** @param callable(Doc): void $mutate */
    #[DataProvider('ruleMessages')]
    public function testRuleViolationMessageError(callable $mutate, string $path, string $message, ?string $id): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(self::rich());
        $mutate($doc);

        $violations = $this->violations($this->putContent($user, $panel['id'], $doc->content()));

        $matching = array_values(array_filter($violations, static fn(array $v): bool => $v['propertyPath'] === $path));
        self::assertNotEmpty($matching, 'Got: ' . implode('; ', array_column($violations, 'propertyPath')));
        self::assertSame($message, $matching[0]['message']);
        self::assertSame($id, $matching[0]['id'] ?? null);
        self::assertSame(1, $this->panelRow($panel['id'])?->version, 'Nothing is written.');
        self::assertSame(0, $this->countRows('terminal_block', $panel['id']));
    }

    public function testRowBlockCapIs60Error(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::minimal();
        $content['terminalRow'] = array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i % 60, 1), range(0, 60));

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        self::assertContains('content.terminalRow', array_column($violations, 'propertyPath'));
        self::assertContains(
            'At most 60 terminal blocks are allowed in the top terminal row.',
            array_column($violations, 'message'),
        );
    }

    public function testBottomRowBlockCapIs60Error(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::minimal();
        $content['bottomTerminalRow'] = array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i % 60, 1), range(0, 60));

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        self::assertContains('content.bottomTerminalRow', array_column($violations, 'propertyPath'));
        self::assertContains('At most 60 terminal blocks are allowed in the bottom terminal row.', array_column($violations, 'message'));
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testBothRowsMayHold60BlocksEachSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(B::minimal(), ['railWidthModules' => 60]);
        $content['terminalRow'] = array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i, 1), range(0, 59));
        $content['bottomTerminalRow'] = array_map(static fn(int $i): array => B::rowBlock(6000 + $i, $i, 1), range(0, 59));

        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertCount(60, $body['content']['terminalRow']);
        self::assertCount(60, $body['content']['bottomTerminalRow']);
        self::assertSame(60, $this->sqlInt("SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND terminal_row = 'top'", [$panel['id']]));
        self::assertSame(60, $this->sqlInt("SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND terminal_row = 'bottom'", [$panel['id']]));
    }

    public function testBottomRowRoundTripSortedByOffsetSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::withBottomRow(self::rich(), [B::rowBlock(53, 12, 4, 'blue', 'N'), B::rowBlock(52, 0, 6, 'green-yellow', 'PE'), B::rowBlock(54, 8, 1)]);

        $put = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        foreach ([$put, $get] as $body) {
            self::assertSame([B::uuid(52), B::uuid(54), B::uuid(53)], array_column($body['content']['bottomTerminalRow'], 'id'));
            self::assertSame(['green-yellow', 'PE', 3, 0], [
                $body['content']['bottomTerminalRow'][0]['colour'],
                $body['content']['bottomTerminalRow'][0]['label'],
                $body['content']['bottomTerminalRow'][0]['widthModules'],
                $body['content']['bottomTerminalRow'][0]['offsetModules'],
            ]);
            self::assertSame([B::uuid(50), B::uuid(51)], array_column($body['content']['terminalRow'], 'id'), 'The top row is not mixed with the bottom row.');
        }
        self::assertSame(2, $this->sqlInt("SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND terminal_row = 'top'", [$panel['id']]));
        self::assertSame(3, $this->sqlInt("SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND terminal_row = 'bottom'", [$panel['id']]));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM terminal_block WHERE panel_id = ? AND terminal_row IS NULL AND rail_id IS NOT NULL', [$panel['id']]));
    }

    public function testBlockMovedBetweenRowsKeepsItsWiresSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::rich();
        $this->saveContent($user, $panel['id'], $content);

        // The editor "moves" block 50 to the bottom row: same id, same wires.
        $moved = $content;
        $moved['bottomTerminalRow'] = [$content['terminalRow'][0]];
        $moved['terminalRow'] = [$content['terminalRow'][1]];
        $body = $this->saveContent($user, $panel['id'], $moved);

        self::assertSame([B::uuid(50)], array_column($body['content']['bottomTerminalRow'], 'id'));
        self::assertSame([B::uuid(51)], array_column($body['content']['terminalRow'], 'id'));
        self::assertSame(['bottom'], $this->connection()->fetchFirstColumn('SELECT terminal_row FROM terminal_block WHERE id = ?', [B::uuid(50)]));
        self::assertContains(B::uuid(40), array_column($body['content']['wires'], 'id'));
        self::assertSame(B::uuid(50), array_column($body['content']['wires'], null, 'id')[B::uuid(40)]['end']['terminalBlockId'] ?? null);
    }

    public function testBottomRowBarShortCircuitIsAWarningSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::withBottomRow(B::with(B::minimal(), ['inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])]]), [B::rowBlock(50, 0, 2)]);
        $content['wires'] = [
            B::wire(40, B::cableWireEnd(11), B::terminalEnd(50, 0, 'bottom')),
            B::wire(41, B::cableWireEnd(12), B::terminalEnd(50, 1, 'top')),
        ];

        $response = $this->putContent($user, $panel['id'], $content);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $data = $this->panelBody($response);
        self::assertCount(1, $data['shortCircuits']);
        self::assertEqualsCanonicalizing([B::uuid(11), B::uuid(12)], $data['shortCircuits'][0]['sources']);
        self::assertEqualsCanonicalizing([B::uuid(40), B::uuid(41)], $data['shortCircuits'][0]['wireIds']);
        self::assertCount(1, $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user))['shortCircuits']);
    }

    public function testBlocksOfBothRowsAtTheSameOffsetAreNotJoinedSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::withBottomRow(B::with(B::minimal(), ['inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])], 'terminalRow' => [B::rowBlock(50, 0, 2)]]), [B::rowBlock(51, 0, 2)]);
        $content['wires'] = [
            B::wire(40, B::cableWireEnd(11), B::terminalEnd(50, 0, 'top')),
            B::wire(41, B::cableWireEnd(12), B::terminalEnd(51, 0, 'bottom')),
        ];

        self::assertSame([], $this->saveContent($user, $panel['id'], $content)['shortCircuits']);
    }

    /** @return iterable<string, array{callable(Doc): void, string}> */
    public static function malformedFields(): iterable
    {
        yield 'terminalRow is missing' => [static function (Doc $c): void {
            $c->unset('terminalRow');
        }, 'content.terminalRow'];
        yield 'terminalRow is a string' => [static function (Doc $c): void {
            $c->set('terminalRow', 'x');
        }, 'content.terminalRow'];
        yield 'bottomTerminalRow is missing (old client)' => [static function (Doc $c): void {
            $c->unset('bottomTerminalRow');
        }, 'content.bottomTerminalRow'];
        yield 'bottomTerminalRow is null' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', null);
        }, 'content.bottomTerminalRow'];
        yield 'bottomTerminalRow is a string' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', 'x');
        }, 'content.bottomTerminalRow'];
        yield 'bottomTerminalRow block has an unknown colour' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', [B::rowBlock(52, 0, 2, 'red')]);
            $c->set('bottomTerminalRow.0.colour', 'purple');
        }, 'content.bottomTerminalRow[0].colour'];
        yield 'inputCableSide is missing (old client)' => [static function (Doc $c): void {
            $c->unset('inputCableSide');
        }, 'content.inputCableSide'];
        yield 'outputCableSide is missing (old client)' => [static function (Doc $c): void {
            $c->unset('outputCableSide');
        }, 'content.outputCableSide'];
        yield 'inputCableSide is null' => [static function (Doc $c): void {
            $c->set('inputCableSide', null);
        }, 'content.inputCableSide'];
        yield 'outputCableSide is null' => [static function (Doc $c): void {
            $c->set('outputCableSide', null);
        }, 'content.outputCableSide'];
        yield 'inputCableSide is unknown' => [static function (Doc $c): void {
            $c->set('inputCableSide', 'middle');
        }, 'content.inputCableSide'];
        yield 'outputCableSide is unknown' => [static function (Doc $c): void {
            $c->set('outputCableSide', 'diagonal');
        }, 'content.outputCableSide'];
        yield 'inputCableSide is wrongly cased' => [static function (Doc $c): void {
            $c->set('inputCableSide', 'Top');
        }, 'content.inputCableSide'];
        yield 'outputCableSide is empty' => [static function (Doc $c): void {
            $c->set('outputCableSide', '');
        }, 'content.outputCableSide'];
        yield 'inputCableSide is a number' => [static function (Doc $c): void {
            $c->set('inputCableSide', 1);
        }, 'content.inputCableSide'];
        yield 'outputCableSide is a list' => [static function (Doc $c): void {
            $c->set('outputCableSide', ['top']);
        }, 'content.outputCableSide'];
        yield 'element kind is unknown' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.kind', 'fuse');
        }, 'content.rails[0].elements[0].kind'];
        yield 'element kind is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.elements.0.kind');
        }, 'content.rails[0].elements[0].kind'];
        yield 'device style is unknown' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.style', 'fuse');
        }, 'content.rails[0].elements[0].style'];
        yield 'device style is wrongly cased' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.style', 'Breaker');
        }, 'content.rails[0].elements[0].style'];
        yield 'device style is a number' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.style', 1);
        }, 'content.rails[0].elements[0].style'];
        yield 'device style key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.elements.0.style');
        }, 'content.rails[0].elements[0].style'];
        yield 'ATS style key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.elements.1.style');
        }, 'content.rails[0].elements[1].style'];
        yield 'device on key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.elements.0.on');
        }, 'content.rails[0].elements[0].on'];
        yield 'device poles key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.elements.0.poles');
        }, 'content.rails[0].elements[0].poles'];
        yield 'device state key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.elements.0.state');
        }, 'content.rails[0].elements[0].state'];
        yield 'ATS state key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.elements.1.state');
        }, 'content.rails[0].elements[1].state'];
        yield 'row block colour key is missing' => [static function (Doc $c): void {
            $c->unset('terminalRow.0.colour');
        }, 'content.terminalRow[0].colour'];
        yield 'rail block colour key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.terminalBlocks.0.colour');
        }, 'content.rails[0].terminalBlocks[0].colour'];
        yield 'ATS state is unknown' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.state', 'C');
        }, 'content.rails[0].elements[1].state'];
        yield 'ATS state is lower case a' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.state', 'a');
        }, 'content.rails[0].elements[1].state'];
        yield 'ATS state is a number' => [static function (Doc $c): void {
            $c->set('rails.0.elements.1.state', 0);
        }, 'content.rails[0].elements[1].state'];
        yield 'block colour is unknown' => [static function (Doc $c): void {
            $c->set('terminalRow.0.colour', 'purple');
        }, 'content.terminalRow[0].colour'];
        yield 'block colour is grey as a string' => [static function (Doc $c): void {
            $c->set('terminalRow.0.colour', 'grey');
        }, 'content.terminalRow[0].colour'];
        yield 'block label is a number' => [static function (Doc $c): void {
            $c->set('terminalRow.0.label', 5);
        }, 'content.terminalRow[0].label'];
        yield 'block label is missing' => [static function (Doc $c): void {
            $c->unset('terminalRow.0.label');
        }, 'content.terminalRow[0].label'];
        yield 'rail block label is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.terminalBlocks.0.label');
        }, 'content.rails[0].terminalBlocks[0].label'];
        yield 'contact side is unknown' => [static function (Doc $c): void {
            $c->set('wires.0.end.contactSide', 'middle');
        }, 'content.wires[0].end.contactSide'];
        yield 'contact side is a number' => [static function (Doc $c): void {
            $c->set('wires.0.end.contactSide', 1);
        }, 'content.wires[0].end.contactSide'];
        yield 'row block terminal count is a string' => [static function (Doc $c): void {
            $c->set('terminalRow.0.terminalCount', '4');
        }, 'content.terminalRow[0].terminalCount'];
    }

    /** @param callable(Doc): void $mutate */
    #[DataProvider('malformedFields')]
    public function testMalformedNewFieldsError(callable $mutate, string $path): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(self::rich());
        $mutate($doc);

        $response = $this->putContent($user, $panel['id'], $doc->content());

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertContains($path, $this->violationPaths($response));
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
        self::assertSame(0, $this->countRows('terminal_block', $panel['id']));
    }

    public function testDeviceStylesRoundTripAndPersistSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(B::minimal(), [
            'rails' => [B::rail(30, [
                B::element(31, 0, 1, 1, true, 'Q1', 'A', 'breaker'),
                B::element(32, 1, 1, 1, true, 'Q2', 'B', 'rcd'),
                B::element(33, 2, 1, 1, false, 'Q3', 'C', 'voltageRelay'),
                B::ats(34, 3, 2),
            ])],
        ]);

        $put = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        foreach ([$put, $get] as $body) {
            $elements = array_column($body['content']['rails'][0]['elements'], null, 'id');
            self::assertSame('breaker', $elements[B::uuid(31)]['style']);
            self::assertSame('rcd', $elements[B::uuid(32)]['style']);
            self::assertSame('voltageRelay', $elements[B::uuid(33)]['style']);
            self::assertArrayHasKey('style', $elements[B::uuid(34)]);
            self::assertNull($elements[B::uuid(34)]['style']);
        }
        foreach ([31 => 'breaker', 32 => 'rcd', 33 => 'voltageRelay'] as $n => $style) {
            self::assertSame($style, $this->connection()->fetchOne('SELECT style FROM element WHERE id = ?', [B::uuid($n)]));
        }
        self::assertNull($this->connection()->fetchOne('SELECT style FROM element WHERE id = ?', [B::uuid(34)]));
    }

    public function testStyleChangeIsReplacedOnSaveSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], B::with(B::minimal(), ['rails' => [B::rail(30, [B::element(31, 0, 1, 1, true, 'Q1', 'A', 'rcd')])]]));

        $body = $this->saveContent($user, $panel['id'], B::with(B::minimal(), ['rails' => [B::rail(30, [B::element(31, 0, 1, 1, true, 'Q1', 'A', 'voltageRelay')])]]));

        self::assertSame('voltageRelay', $body['content']['rails'][0]['elements'][0]['style']);
        self::assertSame('voltageRelay', $this->connection()->fetchOne('SELECT style FROM element WHERE id = ?', [B::uuid(31)]));
    }

    public function testOptionalKeysMayBeOmittedSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(self::rich());
        $doc->unset('wires.1.end.contactSide'); // pole end
        $doc->unset('wires.0.start.contactSide'); // cable wire end
        $doc->unset('wires.0.bends');
        $doc->unset('wires.1.bends');

        $body = $this->saveContent($user, $panel['id'], $doc->content());

        $wires = array_column($body['content']['wires'], null, 'id');
        self::assertSame([], $wires[B::uuid(40)]['bends']);
        self::assertSame([], $wires[B::uuid(41)]['bends']);
        self::assertNull($wires[B::uuid(41)]['end']['contactSide'] ?? null);
    }

    public function testTerminalRowAsJsonObjectError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::rich(), ['terminalRow' => [5 => B::rowBlock(50, 0, 2)]]); // encodes as {"5": ...}

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        $matching = array_values(array_filter($violations, static fn(array $v): bool => 'content.terminalRow' === $v['propertyPath']));
        self::assertSame('Must be a JSON array (list), not an object.', $matching[0]['message'] ?? null);
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testWireEndpointsOfAnotherUsersRowBlockAndAtsError(): void
    {
        $victim = $this->createVerifiedUser();
        $attacker = $this->createVerifiedUser();
        $victimPanel = $this->createPanel($victim, 'Victim');
        $this->saveContent($victim, $victimPanel['id'], self::rich());
        $before = $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim));
        $attackerPanel = $this->createPanel($attacker, 'Attacker');

        $content = B::minimal();
        $content['inputCables'][0] = B::inputCable(510, [B::uuid(511)]);
        $content['wires'] = [
            B::wire(520, B::cableWireEnd(511), B::terminalEnd(50, 0, 'top')),
            B::wire(521, B::poleEnd(33, 0, 'input'), B::cableWireEnd(511)),
        ];

        $violations = $this->violations($this->putContent($attacker, $attackerPanel['id'], $content));

        $paths = array_column($violations, 'propertyPath');
        self::assertContains('content.wires[0].end', $paths);
        self::assertContains('content.wires[1].start', $paths);
        self::assertSame(1, $this->panelRow($attackerPanel['id'])?->version);
        self::assertSame(0, $this->countRows('panel_wire', $attackerPanel['id']));
        self::assertSame(0, $this->countRows('terminal_block', $attackerPanel['id']));
        self::assertEquals($before, $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim)));
    }

    public function testPermanentDeleteCascadesRowBlocksSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], self::rich());
        $survivor = $this->createPanel($user, 'Survivor');
        $other = B::with(B::minimal(), ['inputCables' => [B::inputCable(80)]]);
        $other['terminalRow'] = [B::rowBlock(70, 0, 2)];
        $this->saveContent($user, $survivor['id'], $other);
        self::assertSame(3, $this->countRows('terminal_block', $panel['id']));
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', $user);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, $this->countRows('terminal_block', $panel['id']));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM terminal_block WHERE id IN (?, ?, ?)', [B::uuid(50), B::uuid(51), B::uuid(32)]));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM element WHERE id IN (?, ?, ?)', [B::uuid(33), B::uuid(34), B::uuid(35)]));
        self::assertSame(1, $this->countRows('terminal_block', $survivor['id']));
    }

    public function testSoftDeletedPanelKeepsRowBlocksUntilPermanentDeleteSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], self::rich());

        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);
        self::assertSame(3, $this->countRows('terminal_block', $panel['id']));

        $this->panelApi('POST', '/panels/' . $panel['id'] . '/restore', $user);
        $body = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));
        self::assertCount(2, $body['content']['terminalRow']);
    }
}
