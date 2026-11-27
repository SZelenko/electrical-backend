<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\ApiTestCase;
use Symfony\Component\Yaml\Yaml;

final class ApiDocsTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    public function testDocsOpenApiJsonSuccess(): void
    {
        $response = static::createClient()->request('GET', '/api/v1/docs', [
            'headers' => ['Accept' => 'application/vnd.openapi+json'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('openapi', $response->toArray());
    }

    public function testDocsHtmlUiEnabledOutsideProdSuccess(): void
    {
        // Swagger UI stays enabled outside prod (test env here).
        $response = static::createClient()->request('GET', '/api/v1/docs', [
            'headers' => ['Accept' => 'text/html'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('text/html', $response->getHeaders()['content-type'][0]);
    }

    public function testDocsUiDisabledInProdConfigError(): void
    {
        // The HTML docs UI must be unavailable in prod; verified on the parsed config
        // instead of booting a prod kernel.
        $config = Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/api_platform.yaml');
        /** @var array{'when@prod'?: array{api_platform?: array<string, mixed>}, api_platform: array<string, mixed>} $config */
        $prod = $config['when@prod']['api_platform'] ?? null;

        self::assertIsArray($prod);
        self::assertFalse($prod['enable_swagger_ui'] ?? null);
        self::assertFalse($prod['enable_re_doc'] ?? null);
        self::assertFalse($prod['enable_scalar'] ?? null);
        self::assertFalse($prod['enable_docs'] ?? null);
        self::assertTrue($config['api_platform']['enable_swagger_ui']);
    }

    public function testUnknownRouteError(): void
    {
        static::createClient()->request('GET', '/api/v1/does-not-exist', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonContains(['status' => 404]);
    }
}
