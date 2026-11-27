<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use App\Tests\Factory\UserFactory;

/** One scenario per catalogue `type`: RFC 7807 shape and content type are the same for every auth error. */
final class ErrorCatalogueTest extends AuthApiTestCase
{
    public function testInvalidCredentialsProblemSuccess(): void
    {
        $response = $this->api('POST', '/auth/login', ['email' => 'nobody@example.com', 'password' => 'Wrong-Password-1!']);

        $this->assertProblem($response, AuthError::InvalidCredentials);
    }

    public function testEmailNotVerifiedProblemSuccess(): void
    {
        $user = $this->createUnverifiedUser();

        $response = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

        $this->assertProblem($response, AuthError::EmailNotVerified);
    }

    public function testInvalidCodeProblemSuccess(): void
    {
        $response = $this->api('POST', '/auth/verify-email', ['email' => 'nobody@example.com', 'code' => 'ABCD1234']);

        $this->assertProblem($response, AuthError::InvalidCode);
    }

    public function testTwoFactorRestartProblemSuccess(): void
    {
        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => 'nope', 'code' => 'ABCD1234']);

        $this->assertProblem($response, AuthError::TwoFactorRestart);
    }

    public function testRateLimitedProblemSuccess(): void
    {
        // verify-email only spends the per-user budget while a live code exists.
        $user = $this->createUnverifiedUser();
        $wrong = $this->wrongCodeFor($this->issueVerificationCode($user));
        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => $wrong]);
        }

        $this->issueVerificationCode($user);
        $response = $this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => $wrong]);

        $this->assertProblem($response, AuthError::RateLimited);
        self::assertGreaterThan(0, (int) ($response->getHeaders(false)['retry-after'][0] ?? 0));
    }

    public function testTokenInvalidProblemSuccess(): void
    {
        $this->assertProblem($this->api('GET', '/me'), AuthError::TokenInvalid);
        $this->assertProblem($this->api('GET', '/me', bearer: 'a.b.c'), AuthError::TokenInvalid);
        $this->assertProblem($this->api('POST', '/auth/refresh'), AuthError::TokenInvalid);
    }

    public function testPasswordConfirmationFailedProblemSuccess(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->api(
            'PATCH',
            '/me/two-factor',
            ['enabled' => true, 'password' => 'Wrong-Password-1!'],
            $this->bearerFor($user),
            headers: ['Content-Type' => 'application/merge-patch+json'],
        );

        $this->assertProblem($response, AuthError::PasswordConfirmationFailed);
    }

    public function testOriginRejectedProblemSuccess(): void
    {
        $response = $this->api('POST', '/auth/refresh', headers: ['Origin' => '']);

        $this->assertProblem($response, AuthError::OriginRejected);
    }

    public function testValidationErrorKeepsApiPlatformShapeError(): void
    {
        $response = $this->api('POST', '/auth/register', ['email' => 'bad', 'password' => 'aaaaaaaa']);

        self::assertSame(422, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(422, $data['status']);
        self::assertNotEmpty($data['violations']);
        self::assertContains('email', $this->violationPaths($response));
    }

    public function testOpenApiDocumentsAuthEndpointsSuccess(): void
    {
        $response = $this->client->request('GET', '/api/v1/docs', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);

        self::assertSame(200, $response->getStatusCode());
        /** @var array{paths: array<string, mixed>, components: array{schemas: array<string, mixed>}} $doc */
        $doc = $response->toArray();
        foreach (['/auth/register', '/auth/verify-email', '/auth/resend-code', '/auth/login', '/auth/2fa', '/auth/refresh', '/auth/logout', '/me', '/me/two-factor'] as $path) {
            self::assertArrayHasKey('/api/v1' . $path, $doc['paths'], $path);
        }
        self::assertArrayHasKey('AuthProblem', $doc['components']['schemas']);
    }
}
