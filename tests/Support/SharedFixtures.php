<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Reads `shared/fixtures/short-circuit-cases.json`, shared with the frontend Vitest suite.
 *
 * @phpstan-import-type Content from PanelShapes
 */
final class SharedFixtures
{
    /** Path of the shared fixtures relative to the backend project (`backend/../shared`, `/var/www/shared` in the pod). */
    public static function path(): string
    {
        return \dirname(__DIR__, 2) . '/../shared/fixtures/short-circuit-cases.json';
    }

    /**
     * @return array<string, array{name: string, panel: Content, expected: array<string, mixed>}>
     */
    public static function shortCircuitCases(): array
    {
        $json = file_get_contents(self::path());
        if (false === $json) {
            throw new \RuntimeException('Cannot read ' . self::path());
        }
        /** @var array{cases: list<array{name: string, panel: Content, expected: array<string, mixed>}>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($data['cases'] as $case) {
            $cases[$case['name']] = $case;
        }

        return $cases;
    }
}
