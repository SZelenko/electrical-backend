<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Tests\Support\Doc;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelShapes;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Busbars (stage 5) through `PUT /panels/{id}/content`: rules with exact paths and messages,
 * persistence order, id conflicts and the short-circuit warning.
 *
 * @phpstan-import-type Content from PanelShapes
 */
final class BusbarContentTest extends PanelApiTestCase
{
    /**
     * Rail 30 (24 modules) with the devices 33, 34, 35, 36 (offsets 0 to 3, 2 poles each) and a rail
     * block 32 at offset 4; busbar 60 (anchor 33, count 2); the cable wire 11 is wired to the feed 0
     * (wire 40) and the cable wire 12 to the block (wire 41).
     *
     * @return Content
     */
    private static function base(string $type = 'L'): array
    {
        $content = B::with(B::sample(), [
            'rails.0.elements' => [B::element(33, 0, 1, 2), B::element(34, 1, 1, 2), B::element(35, 2, 1, 2), B::element(36, 3, 1, 2)],
            'rails.0.busbars' => [B::busbar(60, 33, 2, $type)],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::busbarEnd(60, 0)),
                B::wire(41, B::cableWireEnd(12), B::terminalEnd(32, 0)),
            ],
        ]);

        return $content;
    }

    private function busbarRows(string $panelId): int
    {
        return $this->sqlInt(
            'SELECT COUNT(*) FROM busbar b JOIN element e ON e.id = b.first_element_id JOIN din_rail r ON r.id = e.rail_id WHERE r.panel_id = ?',
            [$panelId],
        );
    }

    public function testSaveBusbarRoundTripSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::base('LN');
        $content['wires'][] = B::wire(42, B::cableWireEnd(13), B::busbarEnd(60, 1));

        $put = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        foreach ([$put, $get] as $body) {
            self::assertSame(
                [['id' => B::uuid(60), 'type' => 'LN', 'colour' => null, 'firstElementId' => B::uuid(33), 'count' => 2]],
                $body['content']['rails'][0]['busbars'],
            );
            $wire = $body['content']['wires'][0];
            self::assertSame('busbar', $wire['end']['kind']);
            self::assertSame(B::uuid(60), $wire['end']['busbarId'] ?? null);
            self::assertSame(0, $wire['end']['feedIndex'] ?? null);
            self::assertSame(1, $body['content']['wires'][2]['end']['feedIndex'] ?? null);
            self::assertSame([], $body['shortCircuits']);
        }
        self::assertEquals($put['content'], $get['content']);
        self::assertSame(1, $this->busbarRows($panel['id']));
        self::assertSame(
            [B::uuid(60), 0, null, null],
            $this->connection()->fetchNumeric('SELECT end_busbar_id, end_busbar_feed_index, start_busbar_id, start_busbar_feed_index FROM panel_wire WHERE id = ?', [B::uuid(40)]),
        );
        self::assertSame('LN', $this->connection()->fetchOne('SELECT type FROM busbar WHERE id = ?', [B::uuid(60)]));
        self::assertSame(2, $this->sqlInt('SELECT device_count FROM busbar WHERE id = ?', [B::uuid(60)]));
    }

    public function testBusbarColourRoundTripSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $colours = [null, 'black', 'brown', 'red', 'blue', 'green-yellow'];

        foreach ($colours as $colour) {
            $content = B::with(self::base(), ['rails.0.busbars' => [B::busbar(60, 33, 2, 'L', $colour)]]);

            $put = $this->saveContent($user, $panel['id'], $content);
            $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

            self::assertSame($colour, $put['content']['rails'][0]['busbars'][0]['colour']);
            self::assertSame($colour, $get['content']['rails'][0]['busbars'][0]['colour']);
            self::assertSame($colour, $this->connection()->fetchOne('SELECT colour FROM busbar WHERE id = ?', [B::uuid(60)]) ?: null);
            self::assertSame(['id', 'type', 'colour', 'firstElementId', 'count'], array_keys($get['content']['rails'][0]['busbars'][0]));
        }
    }

    /** @return iterable<string, array{callable(Doc): void, string}> */
    public static function colourViolations(): iterable
    {
        yield 'colour key is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.busbars.0.colour');
        }, 'content.rails[0].busbars[0].colour'];
        yield 'colour is unknown' => [static function (Doc $c): void {
            $c->set('rails.0.busbars.0.colour', 'purple');
        }, 'content.rails[0].busbars[0].colour'];
        yield 'colour is grey as a string' => [static function (Doc $c): void {
            $c->set('rails.0.busbars.0.colour', 'grey');
        }, 'content.rails[0].busbars[0].colour'];
        yield 'colour is a number' => [static function (Doc $c): void {
            $c->set('rails.0.busbars.0.colour', 5);
        }, 'content.rails[0].busbars[0].colour'];
    }

    #[DataProvider('colourViolations')]
    public function testBusbarColourViolationError(callable $mutate, string $path): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(self::base());
        $mutate($doc);

        $violations = $this->violations($this->putContent($user, $panel['id'], $doc->content()));

        self::assertContains($path, array_column($violations, 'propertyPath'));
        self::assertSame(0, $this->busbarRows($panel['id']));
    }

    public function testBusbarsAreOrderedByAnchorOffsetSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails.0.busbars' => [B::busbar(62, 35, 2, 'LN'), B::busbar(61, 33, 2)],
            'wires' => [],
        ]);

        $put = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        foreach ([$put, $get] as $body) {
            self::assertSame([B::uuid(61), B::uuid(62)], array_column($body['content']['rails'][0]['busbars'], 'id'));
            self::assertSame(['L', 'LN'], array_column($body['content']['rails'][0]['busbars'], 'type'));
        }
    }

    public function testBusbarsAreGroupedByTheRailOfTheirAnchorSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails[]' => B::rail(70, [B::element(71, 0, 1), B::element(72, 1, 1), B::element(73, 2, 1)], [], 10, [B::busbar(80, 72, 2, 'LN')]),
        ]);
        $content = B::with($content, ['rails[]' => B::rail(75)]);

        $this->saveContent($user, $panel['id'], $content);
        $rails = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user))['content']['rails'];

        self::assertSame([B::uuid(60)], array_column($rails[0]['busbars'], 'id'));
        self::assertSame([B::uuid(80)], array_column($rails[1]['busbars'], 'id'));
        self::assertSame([], $rails[2]['busbars']);
        self::assertSame(2, $this->busbarRows($panel['id']));
    }

    public function testBusbarOfAnotherCountIsStoredSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails.0.busbars' => [B::busbar(60, 33, 4)],
            'rails.0.terminalBlocks.0.offsetModules' => 6,
        ]);

        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertSame(4, $body['content']['rails'][0]['busbars'][0]['count']);
    }

    public function testReplacingContentRemovesTheBusbarsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], self::base());
        self::assertSame(1, $this->busbarRows($panel['id']));

        $body = $this->saveContent($user, $panel['id'], B::minimal());

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM busbar WHERE id = ?', [B::uuid(60)]));
        self::assertSame(3, $body['version']);
        self::assertSame(0, $this->countRows('din_rail', $panel['id']));
    }

    public function testRemovingTheBusbarKeepsTheDevicesSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], self::base());
        $without = B::with(self::base(), [
            'rails.0.busbars' => [],
            'wires' => [B::wire(41, B::cableWireEnd(12), B::terminalEnd(32, 0))],
        ]);

        $body = $this->saveContent($user, $panel['id'], $without);

        self::assertSame([], $body['content']['rails'][0]['busbars']);
        self::assertCount(4, $body['content']['rails'][0]['elements']);
        self::assertSame(0, $this->busbarRows($panel['id']));
    }

    public function testRepeatedSaveWithTheSameBusbarIdsSuccess(): void
    {
        $this->client->getKernelBrowser()->disableReboot();
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::base('LN');
        $content['wires'][] = B::wire(42, B::cableWireEnd(13), B::busbarEnd(60, 1));

        $first = $this->saveContent($user, $panel['id'], $content);
        $second = $this->saveContent($user, $panel['id'], $content);
        $third = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertSame([2, 3, 4], [$first['version'], $second['version'], $third['version']]);
        self::assertEquals($first['content'], $second['content']);
        self::assertEquals($first['content'], $get['content']);
        self::assertSame(1, $this->busbarRows($panel['id']));
        self::assertSame(3, $this->countRows('panel_wire', $panel['id']));
    }

    public function testRepeatedSaveWithAChangedBusbarSuccess(): void
    {
        $this->client->getKernelBrowser()->disableReboot();
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], self::base('LN'));
        $changed = B::with(self::base('L'), ['rails.0.busbars' => [B::busbar(60, 34, 3, 'L')]]);

        $body = $this->saveContent($user, $panel['id'], $changed);

        self::assertSame(
            [['id' => B::uuid(60), 'type' => 'L', 'colour' => null, 'firstElementId' => B::uuid(34), 'count' => 3]],
            $body['content']['rails'][0]['busbars'],
        );
        self::assertSame(1, $this->busbarRows($panel['id']));
    }

    public function testShortCircuitThroughABusbarIsAWarningSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::base();
        // source 11 on feed L, source 12 on the pole-0 input of the covered device 34: joined by the busbar
        $content['wires'] = [
            B::wire(40, B::cableWireEnd(11), B::busbarEnd(60, 0)),
            B::wire(41, B::cableWireEnd(12), B::poleEnd(34, 0, 'input')),
        ];

        $response = $this->putContent($user, $panel['id'], $content);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $body = $this->panelBody($response);
        self::assertCount(1, $body['shortCircuits']);
        $group = $body['shortCircuits'][0];
        self::assertSame([B::uuid(11), B::uuid(12)], $group['sources']);
        self::assertSame([B::uuid(40), B::uuid(41)], $group['wireIds']);
        $feed = array_values(array_filter($group['terminals'], static fn(array $t): bool => 'busbar' === $t['kind']));
        self::assertCount(1, $feed);
        self::assertSame(B::uuid(60), $feed[0]['busbarId'] ?? null);
        self::assertSame(0, $feed[0]['feedIndex'] ?? null);
        // the warning is stored state, not a one-off: GET returns the same group
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));
        self::assertEquals($body['shortCircuits'], $get['shortCircuits']);
        self::assertSame(2, $this->storedVersion($panel['id']));
    }

    public function testNoShortCircuitBetweenLAndNFeedsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::base('LN');
        $content['wires'] = [
            B::wire(40, B::cableWireEnd(11), B::busbarEnd(60, 0)),
            B::wire(41, B::cableWireEnd(12), B::busbarEnd(60, 1)),
            B::wire(42, B::cableWireEnd(13), B::poleEnd(35, 0, 'input')),
        ];

        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertSame([], $body['shortCircuits']);
    }

    public function testShortCircuitBetweenTheFeedsOfAnLnBusbarSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::base('LN');
        $content['wires'] = [
            B::wire(40, B::cableWireEnd(11), B::busbarEnd(60, 0)),
            B::wire(41, B::cableWireEnd(12), B::busbarEnd(60, 1)),
            B::wire(42, B::busbarEnd(60, 0), B::busbarEnd(60, 1)),
        ];

        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertCount(1, $body['shortCircuits']);
        self::assertSame([B::uuid(11), B::uuid(12)], $body['shortCircuits'][0]['sources']);
    }

    public function testDeviceOutsideTheBusbarIsNotJoinedSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::base();
        $content['wires'] = [
            B::wire(40, B::cableWireEnd(11), B::busbarEnd(60, 0)),
            B::wire(41, B::cableWireEnd(12), B::poleEnd(35, 0, 'input')),
        ];

        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertSame([], $body['shortCircuits']);
    }

    /**
     * Rule violations: name => [content mutator, propertyPath, message, item id].
     *
     * @return iterable<string, array{callable(Doc): void, string, string, ?string}>
     */
    public static function ruleMessages(): iterable
    {
        $set = static function (Doc $c, array $busbars): void {
            $c->set('rails.0.busbars', $busbars);
        };
        yield 'count 1' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 33, 1)]);
        }, 'content.rails[0].busbars[0].count', 'Must be between 2 and 60.', B::uuid(60)];
        yield 'count 61' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 33, 61)]);
        }, 'content.rails[0].busbars[0].count', 'Must be between 2 and 60.', B::uuid(60)];
        yield 'anchor unknown' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 999, 2)]);
        }, 'content.rails[0].busbars[0].firstElementId', 'Must refer to a device on the same rail.', B::uuid(60)];
        yield 'anchor is a terminal block' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 32, 2)]);
        }, 'content.rails[0].busbars[0].firstElementId', 'Must refer to a device on the same rail.', B::uuid(60)];
        yield 'anchor on another rail' => [static function (Doc $c) use ($set): void {
            $c->append('rails', B::rail(70, [B::element(71, 0, 1), B::element(72, 1, 1)]));
            $set($c, [B::busbar(60, 71, 2)]);
        }, 'content.rails[0].busbars[0].firstElementId', 'Must refer to a device on the same rail.', B::uuid(60)];
        yield 'anchor is an ATS' => [static function (Doc $c) use ($set): void {
            $c->append('rails.0.elements', B::ats(37, 8, 2));
            $set($c, [B::busbar(60, 37, 2)]);
        }, 'content.rails[0].busbars[0].firstElementId', 'Must refer to a device, not an ATS.', B::uuid(60)];
        yield 'fewer devices than the count until the rail end' => [static function (Doc $c) use ($set): void {
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', [B::wire(41, B::cableWireEnd(12), B::cableWireEnd(13))]);
            $set($c, [B::busbar(60, 36, 2)]);
        }, 'content.rails[0].busbars[0].count', 'Fewer than 2 consecutive devices from the anchor to the rail end.', B::uuid(60)];
        yield 'a terminal block is in the way' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 33, 5)]);
        }, 'content.rails[0].busbars[0].count', 'The covered items must all be devices (an ATS or a terminal block is in the way).', B::uuid(60)];
        yield 'an ATS is in the way' => [static function (Doc $c) use ($set): void {
            $c->set('rails.0.terminalBlocks.0.offsetModules', 8);
            $c->append('rails.0.elements', B::ats(37, 4, 2));
            $set($c, [B::busbar(60, 33, 5)]);
        }, 'content.rails[0].busbars[0].count', 'The covered items must all be devices (an ATS or a terminal block is in the way).', B::uuid(60)];
        yield 'a free module between the devices' => [static function (Doc $c) use ($set): void {
            $c->set('rails.0.elements.3.offsetModules', 4);
            $c->set('rails.0.terminalBlocks.0.offsetModules', 6);
            $set($c, [B::busbar(60, 33, 4)]);
        }, 'content.rails[0].busbars[0].count', 'The covered devices must be adjacent (no free modules between them).', B::uuid(60)];
        yield 'mixed sidesSwapped' => [static function (Doc $c) use ($set): void {
            $c->set('rails.0.elements.1.sidesSwapped', true);
            $set($c, [B::busbar(60, 33, 2)]);
        }, 'content.rails[0].busbars[0]', 'All covered devices must have the same sidesSwapped.', B::uuid(60)];
        yield 'another busbar covers the same device' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 33, 2), B::busbar(61, 34, 2)]);
        }, 'content.rails[0].busbars[1].firstElementId', 'Covers a device that another busbar already covers.', B::uuid(61)];
        yield 'two busbars on one anchor' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 33, 2), B::busbar(61, 33, 3)]);
        }, 'content.rails[0].busbars[1].firstElementId', 'Covers a device that another busbar already covers.', B::uuid(61)];
        yield 'duplicate busbar id' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(60, 33, 2), B::busbar(60, 35, 2)]);
        }, 'content.rails[0].busbars[1].id', 'Duplicate id within the payload.', B::uuid(60)];
        yield 'busbar id equals an element id' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(35, 33, 2)]);
            $c->set('wires.0.end', B::cableWireEnd(13));
        }, 'content.rails[0].busbars[0].id', 'Duplicate id within the payload.', B::uuid(35)];
        yield 'busbar id equals a wire id' => [static function (Doc $c) use ($set): void {
            $set($c, [B::busbar(41, 33, 2)]);
            $c->set('wires.0.end', B::cableWireEnd(13));
        }, 'content.wires[1].id', 'Duplicate id within the payload.', B::uuid(41)];
        yield 'busbar id is not a uuid' => [static function (Doc $c): void {
            $busbar = B::busbar(60, 33, 2);
            $busbar['id'] = 'BUSBAR';
            $c->set('rails.0.busbars', [$busbar]);
            $c->set('wires.0.end', B::cableWireEnd(13));
        }, 'content.rails[0].busbars[0].id', 'Must be a lowercase RFC 4122 UUID.', null];
        yield 'endpoint refers to an unknown busbar' => [static function (Doc $c): void {
            $c->set('wires.0.end', B::busbarEnd(999, 0));
        }, 'content.wires[0].end', 'Refers to a busbar that is not part of this content.', B::uuid(40)];
        yield 'endpoint refers to a device instead of a busbar' => [static function (Doc $c): void {
            $c->set('wires.0.end', B::busbarEnd(33, 0));
        }, 'content.wires[0].end', 'Refers to a busbar that is not part of this content.', B::uuid(40)];
        yield 'L busbar has no feed 1' => [static function (Doc $c): void {
            $c->set('wires.0.end', B::busbarEnd(60, 1));
        }, 'content.wires[0].end.feedIndex', 'Must be between 0 and 0.', B::uuid(40)];
        yield 'LN busbar has no feed 2' => [static function (Doc $c): void {
            $c->set('rails.0.busbars.0.type', 'LN');
            $c->set('wires.0.end', B::busbarEnd(60, 2));
        }, 'content.wires[0].end.feedIndex', 'Must be between 0 and 1.', B::uuid(40)];
        yield 'negative feed index' => [static function (Doc $c): void {
            $c->set('wires.0.end', B::busbarEnd(60, -1));
        }, 'content.wires[0].end.feedIndex', 'Must be between 0 and 0.', B::uuid(40)];
        yield 'endpoint without a feed index' => [static function (Doc $c): void {
            $c->set('wires.0.end', ['kind' => 'busbar', 'busbarId' => B::uuid(60)]);
        }, 'content.wires[0].end', 'A "busbar" endpoint needs exactly: busbarId and feedIndex.', B::uuid(40)];
        yield 'endpoint without a busbar id' => [static function (Doc $c): void {
            $c->set('wires.0.end', ['kind' => 'busbar', 'feedIndex' => 0]);
        }, 'content.wires[0].end', 'A "busbar" endpoint needs exactly: busbarId and feedIndex.', B::uuid(40)];
        yield 'busbar endpoint with a stray terminal field' => [static function (Doc $c): void {
            $c->set('wires.0.end', B::busbarEnd(60, 0) + ['terminalIndex' => 0]);
        }, 'content.wires[0].end', 'A "busbar" endpoint needs exactly: busbarId and feedIndex.', B::uuid(40)];
        yield 'pole endpoint with a stray feed index' => [static function (Doc $c): void {
            $c->set('wires.0.end', B::poleEnd(34, 0, 'input') + ['feedIndex' => 0]);
        }, 'content.wires[0].end', 'A "pole" endpoint needs exactly: elementId, poleIndex and side.', B::uuid(40)];
        yield 'terminal endpoint with a stray busbar id' => [static function (Doc $c): void {
            $c->set('wires.1.end', B::terminalEnd(32, 0) + ['busbarId' => B::uuid(60)]);
        }, 'content.wires[1].end', 'A "terminal" endpoint needs exactly: terminalBlockId, terminalIndex and contactSide.', B::uuid(41)];
        yield 'cable wire endpoint with a stray busbar id' => [static function (Doc $c): void {
            $c->set('wires.0.start', B::cableWireEnd(11) + ['busbarId' => B::uuid(60)]);
        }, 'content.wires[0].start', 'A "cableWire" endpoint needs exactly: cableWireId.', B::uuid(40)];
        yield 'both ends on the same feed' => [static function (Doc $c): void {
            $c->set('wires.0.start', B::busbarEnd(60, 0));
        }, 'content.wires[0].end', 'The two ends of a wire must differ.', B::uuid(40)];
    }

    /** @param callable(Doc): void $mutate */
    #[DataProvider('ruleMessages')]
    public function testBusbarRuleViolationError(callable $mutate, string $path, string $message, ?string $id): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(self::base());
        $mutate($doc);

        $violations = $this->violations($this->putContent($user, $panel['id'], $doc->content()));

        $matching = array_values(array_filter($violations, static fn(array $v): bool => $v['propertyPath'] === $path));
        self::assertNotEmpty($matching, 'Got: ' . implode('; ', array_column($violations, 'propertyPath')));
        self::assertSame($message, $matching[0]['message']);
        self::assertSame($id, $matching[0]['id'] ?? null);
        self::assertSame(1, $this->panelRow($panel['id'])?->version, 'Nothing is written.');
        self::assertSame(0, $this->busbarRows($panel['id']));
        self::assertSame(0, $this->countRows('din_rail', $panel['id']));
    }

    public function testWireFromBusbarFeedToCoveredDevicePoleInputSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::base();
        $content['wires'][] = B::wire(42, B::busbarEnd(60, 0), B::poleEnd(34, 0, 'input'));

        $response = $this->putContent($user, $panel['id'], $content);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $body = $this->panelBody($response);
        self::assertSame([], $body['shortCircuits']);
        self::assertSame(3, $this->countRows('panel_wire', $panel['id']));
        $wire = array_values(array_filter($body['content']['wires'], static fn(array $w): bool => B::uuid(42) === $w['id']))[0];
        self::assertSame('busbar', $wire['start']['kind']);
        self::assertSame('pole', $wire['end']['kind']);
    }

    public function testBusbarOnTheSecondRailWithWiresRoundTripSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails[]' => B::rail(70, [B::element(71, 0, 1, 2), B::element(72, 1, 1, 2), B::element(73, 2, 1, 2)], [], 10, [B::busbar(80, 72, 2, 'LN')]),
        ]);
        $content['wires'][] = B::wire(42, B::cableWireEnd(13), B::busbarEnd(80, 1));
        $content['wires'][] = B::wire(43, B::busbarEnd(80, 0), B::poleEnd(72, 0, 'input'));

        $put = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        foreach ([$put, $get] as $body) {
            self::assertSame([B::uuid(60)], array_column($body['content']['rails'][0]['busbars'], 'id'));
            self::assertSame(
                [['id' => B::uuid(80), 'type' => 'LN', 'colour' => null, 'firstElementId' => B::uuid(72), 'count' => 2]],
                $body['content']['rails'][1]['busbars'],
            );
            $byId = array_column($body['content']['wires'], null, 'id');
            self::assertSame(B::uuid(80), $byId[B::uuid(42)]['end']['busbarId'] ?? null);
            self::assertSame(1, $byId[B::uuid(42)]['end']['feedIndex'] ?? null);
            self::assertSame(B::uuid(80), $byId[B::uuid(43)]['start']['busbarId'] ?? null);
        }
        self::assertEquals($put['content'], $get['content']);
        self::assertSame(2, $this->busbarRows($panel['id']));
        self::assertSame(
            [B::uuid(80), 1],
            $this->connection()->fetchNumeric('SELECT end_busbar_id, end_busbar_feed_index FROM panel_wire WHERE id = ?', [B::uuid(42)]),
        );
    }

    public function testCoverageWalkIsSkippedOnAnOverlappingRailError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails.0.elements.1.offsetModules' => 0,
            'rails.0.busbars' => [B::busbar(60, 33, 5)],
        ]);

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        $paths = array_column($violations, 'propertyPath');
        self::assertContains('content.rails[0].elements[1].offsetModules', $paths);
        self::assertNotContains('content.rails[0].busbars[0].count', $paths);
        self::assertSame(1, $this->storedVersion($panel['id']));
    }

    public function testCoverageWalkIsSkippedOnARailFitViolationError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails.0.elements.3.offsetModules' => 24,
            'rails.0.busbars' => [B::busbar(60, 33, 5)],
        ]);

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        $paths = array_column($violations, 'propertyPath');
        self::assertContains('content.rails[0].elements[3].offsetModules', $paths);
        self::assertNotContains('content.rails[0].busbars[0].count', $paths);
    }

    public function testBadDeviceLabelDoesNotSuppressABusbarViolationError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails.0.elements.0.label' => str_repeat('x', 17),
            'rails.0.busbars' => [B::busbar(60, 33, 5)],
        ]);

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        $paths = array_column($violations, 'propertyPath');
        self::assertContains('content.rails[0].elements[0].label', $paths);
        self::assertContains('content.rails[0].busbars[0].count', $paths);
    }

    public function testBusbarCountOver30PerRailError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $devices = [];
        $busbars = [];
        for ($i = 0; $i < 60; ++$i) {
            $devices[] = B::element(7000 + $i, $i, 1);
        }
        for ($i = 0; $i < 30; ++$i) {
            $busbars[] = B::busbar(8000 + $i, 7000 + 2 * $i, 2);
        }
        $valid = B::with(B::sample(), [
            'railWidthModules' => 60,
            'rails.0.elements' => $devices,
            'rails.0.terminalBlocks' => [],
            'rails.0.busbars' => $busbars,
            'wires' => [],
        ]);
        $tooMany = B::with($valid, ['rails.0.busbars[]' => B::busbar(8030, 7000, 2)]);

        $violations = $this->violations($this->putContent($user, $panel['id'], $tooMany));

        $matching = array_values(array_filter($violations, static fn(array $v): bool => 'content.rails[0].busbars' === $v['propertyPath']));
        self::assertNotEmpty($matching);
        self::assertSame('At most 30 busbars per rail are allowed.', $matching[0]['message']);
        self::assertSame(1, $this->storedVersion($panel['id']));
        // the 30 busbars themselves are accepted
        $body = $this->saveContent($user, $panel['id'], $valid);
        self::assertCount(30, $body['content']['rails'][0]['busbars']);
        self::assertSame(30, $this->busbarRows($panel['id']));
    }

    /**
     * A rail with 31 busbars, several of them individually invalid (bad count, unknown anchor).
     *
     * @return Content
     */
    private static function overCapContent(): array
    {
        $devices = [];
        for ($i = 0; $i < 40; ++$i) {
            $devices[] = B::element(7000 + $i, $i, 1);
        }
        $busbars = [];
        for ($i = 0; $i < 31; ++$i) {
            $busbars[] = match (true) {
                0 === $i % 5 => B::busbar(8000 + $i, 7000 + $i, 1),
                1 === $i % 5 => B::busbar(8000 + $i, 999, 2),
                default => B::busbar(8000 + $i, 7000 + $i, 2),
            };
        }

        return B::with(B::sample(), [
            'rails.0.elements' => $devices,
            'rails.0.terminalBlocks' => [],
            'rails.0.busbars' => $busbars,
            'wires' => [],
        ]);
    }

    public function testBusbarCapReportsOnlyTheCapViolationError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::overCapContent();
        $content['railWidthModules'] = 60;

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        self::assertSame(['content.rails[0].busbars'], array_column($violations, 'propertyPath'));
        self::assertSame('At most 30 busbars per rail are allowed.', $violations[0]['message']);
        self::assertSame(1, $this->storedVersion($panel['id']));
        self::assertSame(0, $this->busbarRows($panel['id']));
    }

    public function testBusbarsOverTheCapAreNotRegisteredForWiresError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = self::overCapContent();
        $content['railWidthModules'] = 60;
        $content['wires'] = [B::wire(40, B::cableWireEnd(11), B::busbarEnd(8002, 0))];

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        self::assertEqualsCanonicalizing(['content.rails[0].busbars', 'content.wires[0].end'], array_column($violations, 'propertyPath'));
        $wire = array_values(array_filter($violations, static fn(array $v): bool => 'content.wires[0].end' === $v['propertyPath']))[0];
        self::assertSame('Refers to a busbar that is not part of this content.', $wire['message']);
        self::assertSame(1, $this->storedVersion($panel['id']));
    }

    public function testViolationOnOneRailDoesNotHideAnotherRailsBusbarsError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(self::base(), [
            'rails.0.busbars' => [B::busbar(60, 33, 7)],
            'rails[]' => B::rail(70, [B::element(71, 0, 1), B::element(72, 1, 1)], [], 5, [B::busbar(80, 72, 2)]),
        ]);

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        self::assertContains('content.rails[0].busbars[0].count', array_column($violations, 'propertyPath'));
        self::assertContains('content.rails[1].busbars[0].count', array_column($violations, 'propertyPath'));
    }

    public function testBusbarIdUsedInAnotherPanelOfTheSameUserError(): void
    {
        $user = $this->createVerifiedUser();
        $first = $this->createPanel($user, 'First');
        $second = $this->createPanel($user, 'Second');
        $this->saveContent($user, $first['id'], self::base());
        // only the busbar id (and its anchor device) of the first panel is reused
        $reused = B::with(B::minimal(), [
            'rails' => [B::rail(300, [B::element(301, 0, 1, 2), B::element(302, 1, 1, 2)], [], 0, [B::busbar(60, 301, 2)])],
        ]);

        $response = $this->putContent($user, $second['id'], $reused);

        $problem = $this->assertPanelProblem($response, 409, 'panel_content_id_conflict');
        self::assertSame(1, $problem['currentVersion'] ?? null);
        self::assertSame(1, $this->panelRow($second['id'])?->version);
        self::assertSame(0, $this->busbarRows($second['id']));
    }

    public function testBusbarIdOfAnotherUsersPanelError(): void
    {
        $victim = $this->createVerifiedUser();
        $attacker = $this->createVerifiedUser();
        $victimPanel = $this->createPanel($victim, 'Victim');
        $this->saveContent($victim, $victimPanel['id'], self::base());
        $before = $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim));
        $attackerPanel = $this->createPanel($attacker, 'Attacker');
        $reused = B::with(B::minimal(), [
            'rails' => [B::rail(300, [B::element(301, 0, 1, 2), B::element(302, 1, 1, 2)], [], 0, [B::busbar(60, 301, 2)])],
        ]);

        $response = $this->putContent($attacker, $attackerPanel['id'], $reused);

        $this->assertPanelProblem($response, 409, 'panel_content_id_conflict');
        self::assertEquals($before, $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim)));
        self::assertSame(1, $this->busbarRows($victimPanel['id']));
        self::assertSame(1, $this->panelRow($attackerPanel['id'])?->version);
    }

    public function testBusbarWireEndpointOfAnotherPanelsBusbarError(): void
    {
        $victim = $this->createVerifiedUser();
        $attacker = $this->createVerifiedUser();
        $victimPanel = $this->createPanel($victim, 'Victim');
        $this->saveContent($victim, $victimPanel['id'], self::base());
        $attackerPanel = $this->createPanel($attacker, 'Attacker');
        $content = B::with(B::minimal(), ['wires' => [B::wire(520, B::cableWireEnd(11), B::busbarEnd(60, 0))]]);

        $violations = $this->violations($this->putContent($attacker, $attackerPanel['id'], $content));

        self::assertContains('content.wires[0].end', array_column($violations, 'propertyPath'));
        self::assertSame(0, $this->countRows('panel_wire', $attackerPanel['id']));
    }

    public function testStaleVersionWithBusbarsIs409Error(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], self::base());

        $response = $this->putContent($user, $panel['id'], self::base(), 1);

        $problem = $this->assertPanelProblem($response, 409, 'panel_version_conflict');
        self::assertSame(2, $problem['currentVersion'] ?? null);
    }
}
