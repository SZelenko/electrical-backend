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
}
