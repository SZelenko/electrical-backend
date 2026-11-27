<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Entity\Panel\Panel;
use App\Entity\User;
use App\Tests\Functional\Auth\AuthApiTestCase;
use App\Tests\Support\PanelShapes;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared helpers of the panel functional tests (JSON-LD requests as a given user).
 *
 * @phpstan-import-type Content from PanelShapes
 * @phpstan-import-type PanelBody from PanelShapes
 * @phpstan-import-type ListBody from PanelShapes
 * @phpstan-import-type Violation from PanelShapes
 */
abstract class PanelApiTestCase extends AuthApiTestCase
{
    protected const string LD = 'application/ld+json';
    protected const string MERGE_PATCH = 'application/merge-patch+json';

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function panelApi(string $method, string $path, ?User $user, ?array $body = null, array $headers = []): ResponseInterface
    {
        $headers += ['Accept' => self::LD];
        if (null !== $body) {
            $headers += ['Content-Type' => 'PATCH' === $method ? self::MERGE_PATCH : self::LD];
        }

        return $this->api($method, $path, $body, null === $user ? null : $this->bearerFor($user), headers: $headers);
    }

    /** Sends a raw (possibly malformed) body. */
    protected function rawPanelApi(string $method, string $path, User $user, string $body, string $contentType = 'application/json'): ResponseInterface
    {
        return $this->client->request($method, self::BASE . $path, [
            'headers' => ['Accept' => self::LD, 'Content-Type' => $contentType, 'Authorization' => 'Bearer ' . $this->bearerFor($user)],
            'body' => $body,
        ]);
    }

    /** @return PanelBody */
    protected function panelBody(ResponseInterface $response): array
    {
        /** @var PanelBody $body */
        $body = $this->json($response);

        return $body;
    }

    /** @return ListBody */
    protected function listBody(ResponseInterface $response): array
    {
        /** @var ListBody $body */
        $body = $this->json($response);

        return $body;
    }

    /**
     * Creates a panel through the API and returns the response body.
     *
     * @return PanelBody
     */
    protected function createPanel(User $user, string $name = 'Main panel'): array
    {
        $response = $this->panelApi('POST', '/panels', $user, ['name' => $name]);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));

        return $this->panelBody($response);
    }

    /**
     * Saves content (version taken from the current stored panel) and asserts 200.
     *
     * @param Content $content
     *
     * @return PanelBody
     */
    protected function saveContent(User $user, string $panelId, array $content, ?int $version = null): array
    {
        $response = $this->putContent($user, $panelId, $content, $version);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        return $this->panelBody($response);
    }

    /** @param Content $content */
    protected function putContent(User $user, string $panelId, array $content, ?int $version = null): ResponseInterface
    {
        $version ??= $this->storedVersion($panelId);

        return $this->panelApi('PUT', '/panels/' . $panelId . '/content', $user, ['version' => $version, 'content' => $content]);
    }

    protected function storedVersion(string $panelId): int
    {
        $value = $this->connection()->fetchOne('SELECT version FROM panel WHERE id = ?', [$panelId]);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /** The DBAL connection; unlike em() it leaves the entity manager's identity map alone. */
    protected function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    /** @param array<int<0, max>|string, mixed> $params */
    protected function sqlInt(string $sql, array $params = []): int
    {
        $value = $this->connection()->fetchOne($sql, $params);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    protected function panelRow(string $panelId): ?Panel
    {
        return $this->em()->find(Panel::class, Uuid::fromString($panelId));
    }

    protected function panelExists(string $panelId): bool
    {
        return null !== $this->panelRow($panelId);
    }

    protected function countRows(string $table, string $panelId): int
    {
        $value = $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE panel_id = ?', $table), [$panelId]);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * Asserts a panel problem (409 catalogue) and returns the body.
     *
     * @return array{type: string, title: string, status: int, detail: string, currentVersion?: int}
     */
    protected function assertPanelProblem(ResponseInterface $response, int $status, string $type): array
    {
        self::assertSame($status, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('application/problem+json', $response->getHeaders(false)['content-type'][0] ?? '');
        /** @var array{type: string, title: string, status: int, detail: string, currentVersion?: int} $data */
        $data = $this->json($response);
        self::assertSame($type, $data['type']);
        self::assertSame($status, $data['status']);

        return $data;
    }

    /** @return list<Violation> */
    protected function violations(ResponseInterface $response): array
    {
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        $data = $this->json($response);
        /** @var list<Violation> $violations */
        $violations = $data['violations'] ?? [];
        self::assertNotEmpty($violations);

        return $violations;
    }
}
