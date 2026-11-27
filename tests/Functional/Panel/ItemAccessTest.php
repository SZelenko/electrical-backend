<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Entity\User;
use App\Tests\Support\PanelContentBuilder as B;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Every item operation answers 404 for a foreign or missing panel, and ownership is checked
 * before any 409 (state or name conflicts) or 422 (validation of the content).
 */
final class ItemAccessTest extends PanelApiTestCase
{
    private const string MISSING = '0198a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b';

    /** @return iterable<string, array{string}> operation names */
    public static function operations(): iterable
    {
        foreach (['get', 'patch', 'put content', 'put stale content', 'delete', 'restore', 'delete permanently'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function testForeignActivePanelIs404Error(string $operation): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner, 'Owned');
        $before = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $owner));

        $response = $this->perform($operation, $panel['id'], $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
        self::assertEquals($before, $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $owner)));
    }

    #[DataProvider('operations')]
    public function testForeignTrashedPanelIs404Error(string $operation): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner, 'Owned');
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $owner);
        $before = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $owner));

        $response = $this->perform($operation, $panel['id'], $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
        self::assertEquals($before, $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $owner)));
    }

    #[DataProvider('operations')]
    public function testMissingPanelIs404Error(string $operation): void
    {
        $response = $this->perform($operation, self::MISSING, $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('operations')]
    public function testMalformedIdIs404Error(string $operation): void
    {
        $response = $this->perform($operation, 'not-a-uuid', $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('operations')]
    public function testNonRfcVariantIdIs404Error(string $operation): void
    {
        $response = $this->perform($operation, '00000000-0000-0000-0000-000000000001', $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('operations')]
    public function testForeignPanelErrorBodyDoesNotRevealExistenceError(string $operation): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner, 'Secret name');
        $intruder = $this->createVerifiedUser();

        $foreign = $this->perform($operation, $panel['id'], $intruder);
        $missing = $this->perform($operation, self::MISSING, $intruder);

        self::assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        self::assertStringNotContainsString('Secret name', $foreign->getContent(false));
        self::assertStringNotContainsString('currentVersion', $foreign->getContent(false));
    }

    #[DataProvider('operations')]
    public function testOwnPanelOperationsRequireAuthenticationError(string $operation): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $owner);

        $response = $this->perform($operation, $panel['id'], null);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testOwnerReachesOwnPanelInEveryStateSuccess(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner, 'Owned');

        self::assertSame(200, $this->perform('get', $panel['id'], $owner)->getStatusCode());
        self::assertSame(200, $this->perform('patch', $panel['id'], $owner)->getStatusCode());
        self::assertSame(200, $this->perform('put content', $panel['id'], $owner)->getStatusCode());
        self::assertSame(204, $this->perform('delete', $panel['id'], $owner)->getStatusCode());
        self::assertSame(200, $this->perform('get', $panel['id'], $owner)->getStatusCode());
        self::assertSame(200, $this->perform('restore', $panel['id'], $owner)->getStatusCode());
        self::assertSame(204, $this->perform('delete', $panel['id'], $owner)->getStatusCode());
        self::assertSame(204, $this->perform('delete permanently', $panel['id'], $owner)->getStatusCode());
        self::assertSame(404, $this->perform('get', $panel['id'], $owner)->getStatusCode());
    }

    private function perform(string $operation, string $id, ?User $user): ResponseInterface
    {
        $version = $this->versionOrDefault($id);

        return match ($operation) {
            'get' => $this->panelApi('GET', "/panels/$id", $user),
            'patch' => $this->panelApi('PATCH', "/panels/$id", $user, ['name' => 'Renamed ' . $id]),
            'put content' => $this->panelApi('PUT', "/panels/$id/content", $user, ['version' => $version, 'content' => B::sample()]),
            // A stale version: ownership must still win over the version conflict.
            'put stale content' => $this->panelApi('PUT', "/panels/$id/content", $user, ['version' => 99, 'content' => B::sample()]),
            'delete' => $this->panelApi('DELETE', "/panels/$id", $user),
            'restore' => $this->panelApi('POST', "/panels/$id/restore", $user),
            'delete permanently' => $this->panelApi('DELETE', "/panels/$id/permanent", $user),
            default => throw new \LogicException($operation),
        };
    }

    private function versionOrDefault(string $id): int
    {
        if (!preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $id)) {
            return 1;
        }
        $value = $this->connection()->fetchOne('SELECT version FROM panel WHERE id = ?', [$id]);

        return is_numeric($value) ? (int) $value : 1;
    }
}
