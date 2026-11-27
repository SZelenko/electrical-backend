<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class LogoutTest extends AuthApiTestCase
{
    public function testLogoutRevokesTokenAndClearsCookieSuccess(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);
        self::assertSame(1, $this->refreshTokenCount());

        $response = $this->api('POST', '/auth/logout', refreshCookie: $cookie);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent(false));
        $this->assertCookieCleared($response);
        self::assertSame(0, $this->refreshTokenCount());

        // The revoked token cannot be refreshed.
        $this->assertProblem($this->api('POST', '/auth/refresh', refreshCookie: $cookie), AuthError::TokenInvalid);
    }

    public function testLogoutWithoutCookieStill204Success(): void
    {
        $response = $this->api('POST', '/auth/logout');

        self::assertSame(204, $response->getStatusCode());
        $this->assertCookieCleared($response);
    }

    public function testLogoutGarbageCookieStill204Error(): void
    {
        $user = $this->createVerifiedUser();
        $this->loginUser($user);

        $response = $this->api('POST', '/auth/logout', refreshCookie: 'not-a-real-token');

        self::assertSame(204, $response->getStatusCode());
        $this->assertCookieCleared($response);
        self::assertSame(1, $this->refreshTokenCount(), 'Another session is not touched.');
    }

    private function assertCookieCleared(ResponseInterface $response): void
    {
        $line = strtolower($this->refreshCookieLine($response));
        self::assertStringStartsWith('refresh_token=deleted', $line);
        self::assertStringContainsString('path=/api/v1/auth', $line);
        self::assertStringContainsString('httponly', $line);
        self::assertStringContainsString('samesite=strict', $line);
        self::assertMatchesRegularExpression('/expires=\w{3}, \d{2} \w{3} \d{4}/', $line);
    }

    public function testLogoutWithoutOriginError(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);

        $response = $this->api('POST', '/auth/logout', refreshCookie: $cookie, headers: ['Origin' => '']);

        $this->assertProblem($response, AuthError::OriginRejected);
        self::assertSame(1, $this->refreshTokenCount(), 'The token was not revoked.');
    }

    public function testLogoutWithWrongOriginError(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);

        $response = $this->api('POST', '/auth/logout', refreshCookie: $cookie, headers: ['Origin' => 'https://evil.example']);

        $this->assertProblem($response, AuthError::OriginRejected);
        self::assertSame(1, $this->refreshTokenCount());
    }
}
