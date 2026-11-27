<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Auth\AuthError;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents what API Platform cannot discover on its own:
 * - POST /auth/logout, a firewall endpoint;
 * - the RFC 7807 error catalogue (AuthError) on every auth and /me operation.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory', priority: -50)]
final readonly class AuthOpenApiFactory implements OpenApiFactoryInterface
{
    /** Prefix of the api_platform routes (config/routes/api_platform.yaml). */
    private const string PREFIX = '/api/v1';
    private const string PROBLEM_SCHEMA = 'AuthProblem';

    /** Errors per operation (path => method => AuthError cases; plus 422 validation where it applies). */
    private const array ERRORS = [
        self::PREFIX . '/auth/register' => ['post' => [AuthError::RateLimited]],
        self::PREFIX . '/auth/verify-email' => ['post' => [AuthError::InvalidCode, AuthError::RateLimited]],
        self::PREFIX . '/auth/resend-code' => ['post' => [AuthError::RateLimited]],
        self::PREFIX . '/auth/login' => ['post' => [AuthError::InvalidCredentials, AuthError::EmailNotVerified, AuthError::RateLimited]],
        self::PREFIX . '/auth/2fa' => ['post' => [AuthError::InvalidCode, AuthError::TwoFactorRestart, AuthError::RateLimited]],
        self::PREFIX . '/auth/refresh' => ['post' => [AuthError::TokenInvalid, AuthError::OriginRejected]],
        self::PREFIX . '/auth/logout' => ['post' => [AuthError::OriginRejected]],
        self::PREFIX . '/me' => ['get' => [AuthError::TokenInvalid]],
        self::PREFIX . '/me/two-factor' => ['patch' => [AuthError::TokenInvalid, AuthError::PasswordConfirmationFailed, AuthError::RateLimited]],
    ];

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $paths->addPath(self::PREFIX . '/auth/logout', new PathItem(
            post: new Operation(
                operationId: 'postAuthLogout',
                tags: ['Auth'],
                responses: ['204' => new Response('Logged out: the refresh token is revoked and its cookie cleared. Always 204, also without a cookie.')],
                summary: 'Revokes the refresh token from the cookie and clears the cookie. Takes no body.',
                security: [],
            ),
        ));

        foreach (self::ERRORS as $path => $methods) {
            $item = $paths->getPath($path);
            if (null === $item) {
                continue;
            }

            foreach ($methods as $method => $errors) {
                $getter = 'get' . ucfirst($method);
                $operation = $item->{$getter}();
                if (!$operation instanceof Operation) {
                    continue;
                }

                $operation = $this->withErrors($operation, $errors);
                if (!str_starts_with($path, self::PREFIX . '/me')) {
                    $operation = $operation->withSecurity([]);
                }
                $item = $item->{'with' . ucfirst($method)}($operation);
            }
            $paths->addPath($path, $item);
        }

        $components = $openApi->getComponents();
        $schemas = $components->getSchemas() ?? new \ArrayObject();
        $schemas[self::PROBLEM_SCHEMA] = [
            'type' => 'object',
            'description' => 'RFC 7807 problem; `type` is one of: ' . implode(', ', array_map(static fn(AuthError $e): string => $e->value, AuthError::cases())) . '.',
            'required' => ['type', 'title', 'status', 'detail'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => array_map(static fn(AuthError $e): string => $e->value, AuthError::cases())],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
            ],
        ];

        return $openApi->withComponents($components->withSchemas($schemas));
    }

    /** @param list<AuthError> $errors */
    private function withErrors(Operation $operation, array $errors): Operation
    {
        $responses = $operation->getResponses() ?? [];
        unset($responses['404']);
        $byStatus = [];
        foreach ($errors as $error) {
            $byStatus[$error->status()][] = $error;
        }

        foreach ($byStatus as $status => $group) {
            if (isset($responses[(string) $status])) {
                continue;
            }
            $responses[(string) $status] = new Response(
                description: implode(' / ', array_map(static fn(AuthError $e): string => "`{$e->value}`: {$e->title()}", $group)),
                content: new \ArrayObject(['application/problem+json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/' . self::PROBLEM_SCHEMA]))]),
            );
        }

        return $operation->withResponses($responses);
    }
}
