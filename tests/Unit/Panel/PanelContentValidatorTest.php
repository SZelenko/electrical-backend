<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Panel\PanelContentValidator;
use App\Panel\PanelValidationException;
use App\Panel\Violation;
use App\Tests\Support\PanelShapes;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelContentCases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Content from PanelShapes
 */
#[CoversClass(PanelContentValidator::class)]
final class PanelContentValidatorTest extends TestCase
{
    /** @return iterable<string, array{Content}> */
    public static function validContents(): iterable
    {
        return PanelContentCases::validBoundaries();
    }

    /** @return iterable<string, array{Content, string, ?string}> */
    public static function invalidContents(): iterable
    {
        return PanelContentCases::invalid();
    }

    /** @param Content $content */
    #[DataProvider('validContents')]
    public function testValidContentSuccess(array $content): void
    {
        new PanelContentValidator()->validate(B::toContent($content));

        $this->expectNotToPerformAssertions();
    }

    /** @param Content $content */
    #[DataProvider('invalidContents')]
    public function testInvalidContentError(array $content, string $path, ?string $id): void
    {
        try {
            new PanelContentValidator()->validate(B::toContent($content));
            self::fail('The content must be rejected.');
        } catch (PanelValidationException $e) {
            $matching = array_filter(
                $e->violations,
                static fn(Violation $v): bool => $v->propertyPath === $path && (null === $id || $v->id === $id),
            );
            self::assertNotEmpty($matching, \sprintf(
                'Expected a violation at "%s"%s, got: %s',
                $path,
                null === $id ? '' : " for item $id",
                implode('; ', array_map(static fn(Violation $v): string => "{$v->propertyPath} [{$v->id}]", $e->violations)),
            ));
        }
    }

    public function testAllViolationsAreCollectedError(): void
    {
        $content = B::with(B::sample(), [
            'inputCables.0.voltage' => 0,
            'rails.0.elements.0.label' => str_repeat('x', 17),
            'wires.0.end' => B::cableWireEnd(11),
        ]);

        try {
            new PanelContentValidator()->validate(B::toContent($content));
            self::fail('The content must be rejected.');
        } catch (PanelValidationException $e) {
            $paths = array_map(static fn(Violation $v): string => $v->propertyPath, $e->violations);
            self::assertEqualsCanonicalizing(
                ['content.inputCables[0].voltage', 'content.rails[0].elements[0].label', 'content.wires[0].end'],
                $paths,
            );
        }
    }

    public function testViolationListIsCappedError(): void
    {
        $content = B::minimal();
        $content['wires'] = [];
        for ($i = 0; $i < 400; ++$i) {
            $content['wires'][] = B::wire(100 + $i, B::cableWireEnd(900), B::cableWireEnd(901));
        }

        try {
            new PanelContentValidator()->validate(B::toContent($content));
            self::fail('The content must be rejected.');
        } catch (PanelValidationException $e) {
            self::assertCount(200, $e->violations);
        }
    }

    public function testCodePointsNotBytesAreCountedSuccess(): void
    {
        // 16 four-byte code points = 64 bytes (and 32 UTF-16 units): within the 16-character limit.
        $content = B::with(B::sample(), ['rails.0.elements.0.label' => str_repeat('😀', 16)]);

        new PanelContentValidator()->validate(B::toContent($content));

        $this->expectNotToPerformAssertions();
    }

    public function testDecimalsThatAreNotBinaryExactAreAcceptedSuccess(): void
    {
        $content = B::with(B::sample(), [
            'inputCables.0.voltage' => 230.1,
            'inputCables.0.frequency' => 49.95,
            'inputCables.0.crossSection' => 2.35,
            'rails.0.spacingMm' => 0.3,
            'wires.1.bends' => [['x' => 0.7, 'y' => 1234.5]],
        ]);

        new PanelContentValidator()->validate(B::toContent($content));

        $this->expectNotToPerformAssertions();
    }

    /** @return iterable<string, array{list<int>}> wire counts per input cable */
    public static function inputCableShapes(): iterable
    {
        yield '1 cable, 1 wire' => [[1]];
        yield '1 cable, 4 wires' => [[4]];
        yield '2 cables, 3 + 2 wires' => [[3, 2]];
        yield '20 cables, 4 wires' => [array_fill(0, 20, 4)];
    }

    /** @param list<int> $wireCounts */
    #[DataProvider('inputCableShapes')]
    public function testRowCapacityIsTheRailWidthWhateverTheInputCablesSuccess(array $wireCounts): void
    {
        $cables = [];
        foreach ($wireCounts as $i => $count) {
            $cables[] = B::inputCable(100 + 10 * $i, array_map(static fn(int $k): string => B::uuid(1000 + 100 * $i + $k), range(1, $count)));
        }
        // a full 60-module block in each row, starting at module 0
        $content = B::with(B::minimal(), [
            'railWidthModules' => 60,
            'inputCables' => $cables,
        ]);
        $content['terminalRow'] = [B::rowBlock(50, 0, 60)];
        $content['bottomTerminalRow'] = [B::rowBlock(51, 0, 60)];

        new PanelContentValidator()->validate(B::toContent($content));

        $this->expectNotToPerformAssertions();
    }

    /** @param list<int> $wireCounts */
    #[DataProvider('inputCableShapes')]
    public function testRowBlockPastTheRailWidthIsRejectedWhateverTheInputCablesError(array $wireCounts): void
    {
        $cables = [];
        foreach ($wireCounts as $i => $count) {
            $cables[] = B::inputCable(100 + 10 * $i, array_map(static fn(int $k): string => B::uuid(1000 + 100 * $i + $k), range(1, $count)));
        }
        $content = B::with(B::minimal(), ['railWidthModules' => 10, 'inputCables' => $cables]);
        $content['terminalRow'] = [B::rowBlock(50, 9, 4)];
        $content['bottomTerminalRow'] = [B::rowBlock(51, 9, 4)];

        try {
            new PanelContentValidator()->validate(B::toContent($content));
            self::fail('The content must be rejected.');
        } catch (PanelValidationException $e) {
            self::assertEqualsCanonicalizing(
                ['content.terminalRow[0].offsetModules', 'content.bottomTerminalRow[0].offsetModules'],
                array_map(static fn(Violation $v): string => $v->propertyPath, $e->violations),
            );
        }
    }

    /**
     * @param Content      $content
     * @param list<string> $paths
     */
    #[DataProvider('skippedFitChecks')]
    public function testFitChecksAreSkippedWhenTheirInputIsInvalidError(array $content, array $paths): void
    {
        try {
            new PanelContentValidator()->validate(B::toContent($content));
            self::fail('The content must be rejected.');
        } catch (PanelValidationException $e) {
            self::assertEqualsCanonicalizing($paths, array_map(static fn(Violation $v): string => $v->propertyPath, $e->violations));
        }
    }

    /** @return iterable<string, array{Content, list<string>}> */
    public static function skippedFitChecks(): iterable
    {
        yield 'invalid rail width: no fit message, no row message' => [B::with(B::sample(), [
            'railWidthModules' => 0,
            'terminalRow' => [B::rowBlock(50, 0, 2)],
            'bottomTerminalRow' => [B::rowBlock(51, 0, 2)],
        ]), ['content.railWidthModules']];
        yield 'no input cable: no row message' => [B::with(B::sample(), [
            'inputCables' => [],
            'wires' => [],
            'terminalRow' => [B::rowBlock(50, 0, 2)],
        ]), ['content.inputCables']];
        yield 'invalid wire count of an input cable: no row message' => [B::with(B::sample(), [
            'inputCables.0.wires' => [],
            'wires' => [],
            'terminalRow' => [B::rowBlock(50, 0, 2)],
        ]), ['content.inputCables[0].wires']];
        yield 'invalid width of a row block: width message only' => [B::with(B::sample(), [
            'terminalRow' => [B::block(50, 100, 0, 2)],
        ]), ['content.terminalRow[0].widthModules']];
        yield 'invalid width of a rail element: width message only' => [B::with(B::sample(), [
            'rails.0.elements.0.widthModules' => 0,
            'rails.0.elements.0.poles' => [['transit' => false]],
            'wires' => [],
        ]), ['content.rails[0].elements[0].widthModules']];
    }

    public function testHugeBusbarListIsRejectedQuicklyError(): void
    {
        $elements = [];
        $busbars = [];
        for ($i = 0; $i < 3000; ++$i) {
            $elements[] = B::element(10000 + $i, $i % 60, 1);
            $busbars[] = B::busbar(20000 + $i, 900000 + $i, 2);
        }
        $content = B::with(B::minimal(), ['railWidthModules' => 60, 'rails' => [B::rail(30, $elements, [], 0, $busbars)]]);

        $start = hrtime(true);
        try {
            new PanelContentValidator()->validate(B::toContent($content));
            self::fail('The content must be rejected.');
        } catch (PanelValidationException $e) {
            $elapsedSeconds = (hrtime(true) - $start) / 1e9;
            foreach ($e->violations as $violation) {
                self::assertDoesNotMatchRegularExpression('/busbars\[\d+\]/', $violation->propertyPath);
            }
            self::assertLessThan(2.0, $elapsedSeconds, 'Validation of an over-cap busbar list must stay cheap.');
        }
    }
}
