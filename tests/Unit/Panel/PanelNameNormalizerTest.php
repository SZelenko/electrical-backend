<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Panel\PanelNameNormalizer;
use App\Panel\PanelValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PanelNameNormalizer::class)]
final class PanelNameNormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> raw, expected value, expected key */
    public static function validNames(): iterable
    {
        yield 'plain' => ['Main', 'Main', 'main'];
        yield 'trimmed' => ["  Main\t\n", 'Main', 'main'];
        yield 'case folded cyrillic' => ['Щит Один', 'Щит Один', 'щит один'];
        yield 'inner NBSP is kept' => ["a\u{00A0}b", "a\u{00A0}b", "a\u{00A0}b"];
        yield 'inner spaces kept' => ['a  b', 'a  b', 'a  b'];
        yield 'NFD composed to NFC' => ["Cafe\u{0301}", "Caf\u{00E9}", "caf\u{00E9}"];
        yield 'NFC stays' => ["Caf\u{00E9}", "Caf\u{00E9}", "caf\u{00E9}"];
        yield 'exactly 100 characters' => [str_repeat('x', 100), str_repeat('x', 100), str_repeat('x', 100)];
        yield '100 two-byte code points' => [str_repeat('щ', 100), str_repeat('щ', 100), str_repeat('щ', 100)];
        yield '100 four-byte code points' => [str_repeat('😀', 100), str_repeat('😀', 100), str_repeat('😀', 100)];
        yield 'one character' => ['x', 'x', 'x'];
    }

    #[DataProvider('validNames')]
    public function testNormalizeSuccess(string $raw, string $value, string $key): void
    {
        $name = new PanelNameNormalizer()->normalize($raw);

        self::assertSame($value, $name->value);
        self::assertSame($key, $name->key);
    }

    public function testCaseVariantsAndCompositionVariantsShareTheKeySuccess(): void
    {
        $normalizer = new PanelNameNormalizer();

        self::assertSame($normalizer->normalize('ЩИТ')->key, $normalizer->normalize('щит')->key);
        self::assertSame($normalizer->normalize("Caf\u{00E9}")->key, $normalizer->normalize("CAFE\u{0301}")->key);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'only tabs and newlines' => ["\t\n\r"];
        yield '101 characters' => [str_repeat('x', 101)];
        yield '101 two-byte code points' => [str_repeat('щ', 101)];
        yield 'leading NBSP' => ["\u{00A0}Main"];
        yield 'trailing NBSP' => ["Main\u{00A0}"];
        yield 'only NBSP' => ["\u{00A0}"];
        yield 'leading em space' => ["\u{2003}Main"];
        yield 'trailing ideographic space' => ["Main\u{3000}"];
        yield 'leading line separator' => ["\u{2028}Main"];
        yield 'inner control character' => ["a\u{0001}b"];
        yield 'inner newline' => ["a\nb"];
        yield 'invalid UTF-8' => ["a\xFFb"];
    }

    #[DataProvider('invalidNames')]
    public function testNormalizeError(string $raw): void
    {
        try {
            new PanelNameNormalizer()->normalize($raw);
            self::fail('The name must be rejected.');
        } catch (PanelValidationException $e) {
            self::assertCount(1, $e->violations);
            self::assertSame('name', $e->violations[0]->propertyPath);
        }
    }

    public function testViolationPathIsConfigurableError(): void
    {
        try {
            new PanelNameNormalizer()->normalize('', 'custom.path');
            self::fail('The name must be rejected.');
        } catch (PanelValidationException $e) {
            self::assertSame('custom.path', $e->violations[0]->propertyPath);
        }
    }
}
