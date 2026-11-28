<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Tests\Support\Doc;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelShapes;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Stage 5: one rail width per panel, cable labels and the terminal-row capacity (rail width minus
 * the input cables) through `PUT /panels/{id}/content`.
 *
 * @phpstan-import-type Content from PanelShapes
 */
final class RailWidthLabelsAndRowCapacityTest extends PanelApiTestCase
{
    public function testNewPanelHasTheDefaultRailWidthAndEmptyLabelSuccess(): void
    {
        $user = $this->createVerifiedUser();

        $panel = $this->createPanel($user);

        self::assertSame(60, $panel['content']['railWidthModules']);
        self::assertSame('', $panel['content']['inputCables'][0]['label']);
        self::assertSame(60, $this->sqlInt('SELECT rail_width_modules FROM panel WHERE id = ?', [$panel['id']]));
        self::assertSame([], $panel['content']['rails']);
    }

    /** @return iterable<string, array{int}> */
    public static function validWidths(): iterable
    {
        yield 'minimum' => [1];
        yield 'typical' => [24];
        yield 'maximum' => [60];
    }

    #[DataProvider('validWidths')]
    public function testSaveRailWidthSuccess(int $width): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(B::minimal(), ['railWidthModules' => $width]);

        $put = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertSame($width, $put['content']['railWidthModules']);
        self::assertSame($width, $get['content']['railWidthModules']);
        self::assertSame($width, $this->sqlInt('SELECT rail_width_modules FROM panel WHERE id = ?', [$panel['id']]));
    }

    public function testChangingOnlyTheRailWidthBumpsTheVersionSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], B::with(B::minimal(), ['railWidthModules' => 30]));

        $body = $this->saveContent($user, $panel['id'], B::with(B::minimal(), ['railWidthModules' => 31]));

        self::assertSame(3, $body['version']);
        self::assertSame(31, $this->sqlInt('SELECT rail_width_modules FROM panel WHERE id = ?', [$panel['id']]));
    }

    public function testNarrowingTheRailWidthBelowTheBottomRowIsRejectedError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $wide = B::with(B::minimal(), ['railWidthModules' => 24, 'bottomTerminalRow' => [B::rowBlock(50, 20, 4)]]);
        $this->saveContent($user, $panel['id'], $wide);

        $narrow = B::with($wide, ['railWidthModules' => 21]);
        $violations = $this->violations($this->putContent($user, $panel['id'], $narrow, 2));

        self::assertSame(['content.bottomTerminalRow[0].offsetModules'], array_column($violations, 'propertyPath'));
        self::assertSame('Does not fit within the bottom terminal row (rail width).', $violations[0]['message']);
        self::assertSame(2, $this->panelRow($panel['id'])?->version);
        self::assertSame(24, $this->sqlInt('SELECT rail_width_modules FROM panel WHERE id = ?', [$panel['id']]));
    }

    public function testNarrowingTheRailWidthExactlyToTheBottomRowSucceedsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $wide = B::with(B::minimal(), ['railWidthModules' => 24, 'bottomTerminalRow' => [B::rowBlock(50, 20, 4)]]);
        $this->saveContent($user, $panel['id'], $wide);

        $body = $this->saveContent($user, $panel['id'], B::with($wide, ['railWidthModules' => 22]), 2);

        self::assertSame(22, $body['content']['railWidthModules']);
        self::assertSame(3, $body['version']);
    }

    public function testEveryRailSharesThePanelWidthSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(B::sample(), [
            'railWidthModules' => 10,
            'rails' => [B::rail(30, [B::element(31, 0, 2, 2)], [B::block(32, 9, 1, 2)]), B::rail(70, [B::element(71, 8, 2)])],
            'wires' => [],
        ]);

        $body = $this->saveContent($user, $panel['id'], $content);

        self::assertCount(2, $body['content']['rails']);
        self::assertArrayNotHasKey('widthModules', $body['content']['rails'][0]);
        self::assertSame(10, $body['content']['railWidthModules']);
    }

    /** @return iterable<string, array{callable(Doc): void, string, string, ?string}> */
    public static function widthAndFitViolations(): iterable
    {
        yield 'rail width 0' => [static function (Doc $c): void {
            $c->set('railWidthModules', 0);
        }, 'content.railWidthModules', 'Must be between 1 and 60.', null];
        yield 'rail width 61' => [static function (Doc $c): void {
            $c->set('railWidthModules', 61);
        }, 'content.railWidthModules', 'Must be between 1 and 60.', null];
        yield 'rail width negative' => [static function (Doc $c): void {
            $c->set('railWidthModules', -1);
        }, 'content.railWidthModules', 'Must be between 1 and 60.', null];
        yield 'element ends one module past the rail' => [static function (Doc $c): void {
            $c->set('railWidthModules', 24);
            $c->append('rails.0.elements', B::element(33, 23, 2));
        }, 'content.rails[0].elements[1].offsetModules', 'Does not fit within the rail width.', B::uuid(33)];
        yield 'rail block ends one module past the rail' => [static function (Doc $c): void {
            $c->set('railWidthModules', 4);
        }, 'content.rails[0].terminalBlocks[0].offsetModules', 'Does not fit within the rail width.', B::uuid(32)];
        yield 'shrinking below an element' => [static function (Doc $c): void {
            $c->set('railWidthModules', 5);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', []);
            $c->append('rails.0.elements', B::element(33, 4, 2));
        }, 'content.rails[0].elements[1].offsetModules', 'Does not fit within the rail width.', B::uuid(33)];
        yield 'fit is checked on the second rail too' => [static function (Doc $c): void {
            $c->set('railWidthModules', 10);
            $c->append('rails', B::rail(70, [B::element(71, 9, 2)]));
        }, 'content.rails[1].elements[0].offsetModules', 'Does not fit within the rail width.', B::uuid(71)];
        yield 'top row block ends one module past the rail width' => [static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 23, 4));
        }, 'content.terminalRow[0].offsetModules', 'Does not fit within the top terminal row (rail width).', B::uuid(50)];
        yield 'top row block starts at the rail width' => [static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 24, 1));
        }, 'content.terminalRow[0].offsetModules', 'Does not fit within the top terminal row (rail width).', B::uuid(50)];
        yield 'bottom row block ends one module past the rail width' => [static function (Doc $c): void {
            $c->append('bottomTerminalRow', B::rowBlock(50, 23, 4));
        }, 'content.bottomTerminalRow[0].offsetModules', 'Does not fit within the bottom terminal row (rail width).', B::uuid(50)];
        yield 'bottom row block starts at the rail width' => [static function (Doc $c): void {
            $c->append('bottomTerminalRow', B::rowBlock(50, 24, 1));
        }, 'content.bottomTerminalRow[0].offsetModules', 'Does not fit within the bottom terminal row (rail width).', B::uuid(50)];
        yield 'a block past the rail width is rejected whatever the input cables (4 wires)' => [static function (Doc $c): void {
            $c->set('inputCables.0.wires', array_map(static fn(int $i): array => ['id' => B::uuid($i), 'colour' => 'brown'], [11, 12, 13, 14]));
            $c->append('terminalRow', B::rowBlock(50, 23, 4));
        }, 'content.terminalRow[0].offsetModules', 'Does not fit within the top terminal row (rail width).', B::uuid(50)];
        yield 'rail width of one module and a two-module top row block' => [static function (Doc $c): void {
            $c->set('railWidthModules', 1);
            $c->set('rails.0.elements', []);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', []);
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
        }, 'content.terminalRow[0].offsetModules', 'Does not fit within the top terminal row (rail width).', B::uuid(50)];
        yield 'the bottom row overflows a narrowed rail while the top row fits' => [static function (Doc $c): void {
            $c->set('railWidthModules', 10);
            $c->append('terminalRow', B::rowBlock(50, 0, 4));
            $c->append('bottomTerminalRow', B::rowBlock(51, 9, 4));
        }, 'content.bottomTerminalRow[0].offsetModules', 'Does not fit within the bottom terminal row (rail width).', B::uuid(51)];
    }

    /** @param callable(Doc): void $mutate */
    #[DataProvider('widthAndFitViolations')]
    public function testRailWidthAndFitViolationError(callable $mutate, string $path, string $message, ?string $id): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(B::sample());
        $mutate($doc);

        $violations = $this->violations($this->putContent($user, $panel['id'], $doc->content()));

        $matching = array_values(array_filter($violations, static fn(array $v): bool => $v['propertyPath'] === $path));
        self::assertNotEmpty($matching, 'Got: ' . implode('; ', array_column($violations, 'propertyPath')));
        self::assertSame($message, $matching[0]['message']);
        self::assertSame($id, $matching[0]['id'] ?? null);
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
        self::assertSame(60, $this->sqlInt('SELECT rail_width_modules FROM panel WHERE id = ?', [$panel['id']]), 'The stored width is untouched.');
    }

    /** @return iterable<string, array{callable(Doc): void, int, int}> */
    public static function rowCapacityBoundaries(): iterable
    {
        yield 'block ends exactly at the rail width' => [static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 22, 4));
        }, 1, 0];
        yield 'one-module block in the last module' => [static function (Doc $c): void {
            $c->append('terminalRow', B::rowBlock(50, 23, 1));
        }, 1, 0];
        yield 'whole top row filled' => [static function (Doc $c): void {
            $c->set('terminalRow', array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i, 1), range(0, 23)));
        }, 24, 0];
        yield 'whole bottom row filled' => [static function (Doc $c): void {
            $c->set('bottomTerminalRow', array_map(static fn(int $i): array => B::rowBlock(5000 + $i, $i, 1), range(0, 23)));
        }, 0, 24];
        yield 'both rows filled independently' => [static function (Doc $c): void {
            $c->set('terminalRow', [B::rowBlock(50, 0, 48)]);
            $c->set('bottomTerminalRow', [B::rowBlock(51, 0, 48)]);
        }, 1, 1];
        yield 'capacity of exactly one module (rail width 1)' => [static function (Doc $c): void {
            $c->set('railWidthModules', 1);
            $c->set('rails.0.elements', []);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', []);
            $c->append('terminalRow', B::rowBlock(50, 0, 1));
            $c->append('bottomTerminalRow', B::rowBlock(51, 0, 2));
        }, 1, 1];
        yield 'capacity is the rail width with many input cables (4 cables x 4 wires)' => [static function (Doc $c): void {
            $c->set('inputCables', array_map(
                static fn(int $i): array => B::inputCable(100 + 10 * $i, array_map(static fn(int $k): string => B::uuid(1000 + 100 * $i + $k), [1, 2, 3, 4])),
                [0, 1, 2, 3],
            ));
            $c->set('wires', []);
            $c->set('railWidthModules', 24);
            $c->append('terminalRow', B::rowBlock(50, 0, 48));
            $c->append('bottomTerminalRow', B::rowBlock(51, 0, 48));
        }, 1, 1];
        yield 'terminal rows of a wide rail' => [static function (Doc $c): void {
            $c->set('railWidthModules', 60);
            $c->append('terminalRow', B::rowBlock(50, 0, 60));
            $c->append('bottomTerminalRow', B::rowBlock(51, 0, 60));
        }, 1, 1];
        yield 'rail width without any row block' => [static function (Doc $c): void {
            $c->set('railWidthModules', 1);
            $c->set('rails.0.elements', []);
            $c->set('rails.0.terminalBlocks', []);
            $c->set('wires', []);
        }, 0, 0];
    }

    /** @param callable(Doc): void $mutate */
    #[DataProvider('rowCapacityBoundaries')]
    public function testRowCapacityBoundarySuccess(callable $mutate, int $topBlocks, int $bottomBlocks): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(B::sample());
        $mutate($doc);

        $body = $this->saveContent($user, $panel['id'], $doc->content());

        self::assertCount($topBlocks, $body['content']['terminalRow']);
        self::assertCount($bottomBlocks, $body['content']['bottomTerminalRow']);
        self::assertSame(2, $body['version']);
    }

    public function testCableLabelsRoundTripSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(B::sample(), [
            'inputCables.0.label' => str_repeat('😀', 16),
            'outputCables.0.label' => 'Світло ' . str_repeat('щ', 9),
            'outputCables[]' => B::outputCable(22, [B::uuid(23)], 'Sockets', ''),
        ]);

        $put = $this->saveContent($user, $panel['id'], $content);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        foreach ([$put, $get] as $body) {
            self::assertSame(str_repeat('😀', 16), $body['content']['inputCables'][0]['label']);
            self::assertSame('Світло ' . str_repeat('щ', 9), $body['content']['outputCables'][0]['label']);
            self::assertSame('', $body['content']['outputCables'][1]['label']);
        }
        self::assertSame(str_repeat('😀', 16), $this->connection()->fetchOne('SELECT label FROM cable WHERE id = ?', [B::uuid(10)]));
        self::assertSame('', $this->connection()->fetchOne('SELECT label FROM cable WHERE id = ?', [B::uuid(22)]));
    }

    public function testEmptyLabelIsAcceptedForBothKindsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $body = $this->saveContent($user, $panel['id'], B::with(B::sample(), ['inputCables.0.label' => '', 'outputCables.0.label' => '']));

        self::assertSame('', $body['content']['inputCables'][0]['label']);
        self::assertSame('', $body['content']['outputCables'][0]['label']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function tooLongLabels(): iterable
    {
        yield 'input, 17 ASCII characters' => ['inputCables.0.label', str_repeat('x', 17), 'content.inputCables[0].label'];
        yield 'input, 17 two-byte code points' => ['inputCables.0.label', str_repeat('щ', 17), 'content.inputCables[0].label'];
        yield 'input, 17 four-byte code points' => ['inputCables.0.label', str_repeat('😀', 17), 'content.inputCables[0].label'];
        yield 'output, 17 ASCII characters' => ['outputCables.0.label', str_repeat('x', 17), 'content.outputCables[0].label'];
        yield 'output, 17 two-byte code points' => ['outputCables.0.label', str_repeat('щ', 17), 'content.outputCables[0].label'];
        yield 'output, 17 four-byte code points' => ['outputCables.0.label', str_repeat('😀', 17), 'content.outputCables[0].label'];
    }

    #[DataProvider('tooLongLabels')]
    public function testCableLabelTooLongError(string $docPath, string $label, string $path): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $violations = $this->violations($this->putContent($user, $panel['id'], B::with(B::sample(), [$docPath => $label])));

        $matching = array_values(array_filter($violations, static fn(array $v): bool => $v['propertyPath'] === $path));
        self::assertCount(1, $matching);
        self::assertSame('Must be at most 16 characters long.', $matching[0]['message']);
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingLabels(): iterable
    {
        yield 'input cable' => ['inputCables.0.label', 'content.inputCables[0].label'];
        yield 'output cable' => ['outputCables.0.label', 'content.outputCables[0].label'];
    }

    #[DataProvider('missingLabels')]
    public function testMissingCableLabelKeyError(string $docPath, string $path): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(B::sample());
        $doc->unset($docPath);

        $response = $this->putContent($user, $panel['id'], $doc->content());

        self::assertContains($path, array_column($this->violations($response), 'propertyPath'));
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testMissingRailWidthKeyError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(B::sample());
        $doc->unset('railWidthModules');

        $response = $this->putContent($user, $panel['id'], $doc->content());

        self::assertContains('content.railWidthModules', array_column($this->violations($response), 'propertyPath'));
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testLegacyRailWidthKeyInTheRailIsIgnoredSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(B::sample(), ['rails.0.widthModules' => 7]);

        $body = $this->saveContent($user, $panel['id'], $content);

        // Unknown fields are ignored like everywhere else; the panel value alone defines the width.
        self::assertSame(24, $body['content']['railWidthModules']);
        self::assertArrayNotHasKey('widthModules', $body['content']['rails'][0]);
        self::assertSame(24, $this->sqlInt('SELECT rail_width_modules FROM panel WHERE id = ?', [$panel['id']]));
    }
}
