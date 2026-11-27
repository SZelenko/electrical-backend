<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Tests\Support\PanelShapes;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelContentCases;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @phpstan-import-type Content from PanelShapes
 */
final class GetPanelTest extends PanelApiTestCase
{
    public function testGetPanelSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $created = $this->createPanel($user, 'Main panel');

        $response = $this->panelApi('GET', '/panels/' . $created['id'], $user);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->panelBody($response);
        self::assertSame('/api/v1/panels/' . $created['id'], $this->json($response)['@id']);
        self::assertSame($created['id'], $data['id']);
        self::assertSame('Main panel', $data['name']);
        self::assertSame(1, $data['version']);
        self::assertNull($data['deletedAt'] ?? null);
        self::assertEquals($created['content'], $data['content']);
        self::assertSame([], $data['shortCircuits']);
    }

    public function testGetContentRoundTripEqualsPutPayloadSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $payload = B::sample();
        $put = $this->saveContent($user, $panel['id'], $payload);

        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertEquals(self::stripJsonLd($payload), self::stripJsonLd($get['content']));
        self::assertEquals(self::stripJsonLd($payload), self::stripJsonLd($put['content']));
        self::assertSame(2, $get['version']);
    }

    /** @param Content $payload */
    #[DataProvider('validContents')]
    public function testGetContentRoundTripOfBoundaryContentsSuccess(array $payload): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], $payload);

        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertEquals(self::stripJsonLd(self::sortedByOffset($payload)), self::stripJsonLd($get['content']));
    }

    /** @return iterable<string, array{Content}> */
    public static function validContents(): iterable
    {
        return PanelContentCases::validBoundaries();
    }

    public function testGetElementsAreOrderedByOffsetSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $payload = B::with(B::sample(), [
            'rails.0.elements' => [B::element(33, 10, 1), B::element(31, 0, 2, 2), B::element(34, 7, 1)],
        ]);

        $this->saveContent($user, $panel['id'], $payload);
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertSame(
            [B::uuid(31), B::uuid(34), B::uuid(33)],
            array_column($get['content']['rails'][0]['elements'], 'id'),
        );
    }

    public function testGetPanelContentPreservesOrderOfListsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $sample = B::sample();
        $payload = B::with($sample, [
            'inputCables' => [B::inputCable(70), B::inputCable(10, [B::uuid(11), B::uuid(12), B::uuid(13)]), B::inputCable(50)],
            'rails' => [B::rail(90, 10), B::rail(30, 24, [B::element(31, 0, 2, 2)], [B::block(32, 4, 1, 4)]), B::rail(60, 5)],
            'wires' => array_reverse($sample['wires']),
            'wires.0.bends' => [['x' => 3, 'y' => 1], ['x' => 1, 'y' => 3], ['x' => 2, 'y' => 2]],
        ]);

        $this->saveContent($user, $panel['id'], $payload);
        $content = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user))['content'];

        self::assertSame([B::uuid(70), B::uuid(10), B::uuid(50)], array_column($content['inputCables'], 'id'));
        self::assertSame([B::uuid(90), B::uuid(30), B::uuid(60)], array_column($content['rails'], 'id'));
        self::assertSame([B::uuid(42), B::uuid(41), B::uuid(40)], array_column($content['wires'], 'id'));
        self::assertSame([[3, 1], [1, 3], [2, 2]], array_map(static fn(array $b): array => [$b['x'], $b['y']], $content['wires'][0]['bends']));
    }

    public function testGetNestedContentHasNoJsonLdNoiseSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], B::with(B::sample(), ['wires[]' => B::wire(43, B::cableWireEnd(12), B::cableWireEnd(11))]));

        $data = $this->json($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertSame('Panel', $data['@type']);
        self::assertNotEmpty($data['shortCircuits']);
        $found = [];
        self::assertIsArray($data['content']);
        self::assertIsArray($data['shortCircuits']);
        array_walk_recursive($data['content'], static function (mixed $v, string|int $k) use (&$found): void {
            if ('@type' === $k || '@id' === $k) {
                $found[] = $k;
            }
        });
        array_walk_recursive($data['shortCircuits'], static function (mixed $v, string|int $k) use (&$found): void {
            if ('@type' === $k || '@id' === $k) {
                $found[] = $k;
            }
        });
        self::assertSame([], $found, 'Nested content and shortCircuits carry no @type/@id.');
    }

    public function testGetTrashedPanelSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'Gone');
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        $response = $this->panelApi('GET', '/panels/' . $panel['id'], $user);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->panelBody($response);
        self::assertNotNull($data['deletedAt'] ?? null);
        self::assertSame(2, $data['version']);
    }

    public function testGetPanelPlainJsonSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->api('GET', '/panels/' . $panel['id'], null, $this->bearerFor($user));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($panel['id'], $this->json($response)['id']);
    }

    public function testGetPanelWithShortCircuitWarningSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = [
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [],
            'wires' => [B::wire(40, B::cableWireEnd(11), B::cableWireEnd(12))],
        ];
        $this->saveContent($user, $panel['id'], $content);

        $data = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertCount(1, $data['shortCircuits']);
        self::assertEqualsCanonicalizing([B::uuid(11), B::uuid(12)], $data['shortCircuits'][0]['sources']);
        self::assertSame([B::uuid(40)], $data['shortCircuits'][0]['wireIds']);
    }

    public function testGetPanelUnauthorizedError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->panelApi('GET', '/panels/' . $panel['id'], null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('token_invalid', $this->json($response)['type']);
    }

    public function testGetForeignPanelError(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner);

        $response = $this->panelApi('GET', '/panels/' . $panel['id'], $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString($panel['name'], $response->getContent(false));
    }

    public function testGetForeignTrashedPanelError(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $owner);

        self::assertSame(404, $this->panelApi('GET', '/panels/' . $panel['id'], $this->createVerifiedUser())->getStatusCode());
    }

    public function testGetMissingPanelError(): void
    {
        $user = $this->createVerifiedUser();

        self::assertSame(404, $this->panelApi('GET', '/panels/0198a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', $user)->getStatusCode());
    }

    public function testGetMalformedIdError(): void
    {
        $user = $this->createVerifiedUser();

        self::assertSame(404, $this->panelApi('GET', '/panels/not-a-uuid', $user)->getStatusCode());
        self::assertSame(404, $this->panelApi('GET', '/panels/123', $user)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function stripJsonLd(array $content): array
    {
        /** @var array<string, mixed> $stripped */
        $stripped = self::stripKeys($content);

        return $stripped;
    }

    private static function stripKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (\in_array($key, ['@type', '@id', '@context'], true) || null === $item) {
                continue;
            }
            $result[$key] = self::stripKeys($item);
        }

        return $result;
    }

    /**
     * The response orders elements and terminal blocks of a rail by offset.
     *
     * @param Content $content
     *
     * @return array<string, mixed>
     */
    private static function sortedByOffset(array $content): array
    {
        foreach ($content['rails'] as &$rail) {
            usort($rail['elements'], static fn(array $a, array $b): int => $a['offsetModules'] <=> $b['offsetModules']);
            usort($rail['terminalBlocks'], static fn(array $a, array $b): int => $a['offsetModules'] <=> $b['offsetModules']);
        }

        return $content;
    }
}
