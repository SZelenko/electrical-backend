<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;

final class RefreshTest extends AuthApiTestCase
{
    public function testRefreshRotatesTokenSuccess(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);

        $response = $this->api('POST', '/auth/refresh', refreshCookie: $cookie);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $this->str($response, 'token'));
        $rotated = $this->refreshCookieValue($response);
        self::assertNotNull($rotated);
        self::assertNotSame($cookie, $rotated);
        $this->assertRefreshCookieAttributes($this->refreshCookieLine($response));
        self::assertSame(1, $this->refreshTokenCount(), 'Single use: the old token is replaced, not added.');

        // The rotated cookie works again.
        self::assertSame(200, $this->api('POST', '/auth/refresh', refreshCookie: $rotated)->getStatusCode());
    }

    public function testRefreshReusedOldTokenError(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);
        self::assertSame(200, $this->api('POST', '/auth/refresh', refreshCookie: $cookie)->getStatusCode());

        $response = $this->api('POST', '/auth/refresh', refreshCookie: $cookie);

        $this->assertProblem($response, AuthError::TokenInvalid);
    }

    public function testRefreshWithoutCookieError(): void
    {
        $response = $this->api('POST', '/auth/refresh');

        $this->assertProblem($response, AuthError::TokenInvalid);
    }

    public function testRefreshGarbageCookieError(): void
    {
        $response = $this->api('POST', '/auth/refresh', refreshCookie: 'not-a-real-token');

        $this->assertProblem($response, AuthError::TokenInvalid);
    }

    public function testRefreshExpiredTokenError(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);
        // The bundle checks validity against the real clock, so age the stored token directly.
        $this->em()->getConnection()->executeStatement("UPDATE refresh_token SET valid = NOW() - INTERVAL '1 day'");

        $response = $this->api('POST', '/auth/refresh', refreshCookie: $cookie);

        $this->assertProblem($response, AuthError::TokenInvalid);
    }

    /**
     * Documents the actual behaviour: a bad or expired Authorization header does not break refresh,
     * the firewall's refresh authenticator handles the request before the JWT one. The frontend rule
     * "never send Authorization to /auth/*" is still the contract.
     */
    public function testRefreshWithExpiredBearerAndValidCookieIgnoresBearerSuccess(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);
        /** @var JWTEncoderInterface $encoder */
        $encoder = static::getContainer()->get('lexik_jwt_authentication.encoder');
        $expired = $encoder->encode(['username' => $user->getUserIdentifier(), 'iat' => time() - 3600, 'exp' => time() - 1800]);

        $withExpired = $this->api('POST', '/auth/refresh', bearer: $expired, refreshCookie: $cookie);
        self::assertSame(200, $withExpired->getStatusCode());
        $rotated = (string) $this->refreshCookieValue($withExpired);

        $withGarbage = $this->api('POST', '/auth/refresh', bearer: 'garbage.token.value', refreshCookie: $rotated);
        self::assertSame(200, $withGarbage->getStatusCode());
    }

    public function testRefreshExpiredBearerWithoutCookieError(): void
    {
        $response = $this->api('POST', '/auth/refresh', bearer: 'garbage.token.value');

        $this->assertProblem($response, AuthError::TokenInvalid);
    }

    public function testRefreshResponseIsNotCachedSuccess(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);

        $this->assertNoStore($this->api('POST', '/auth/refresh', refreshCookie: $cookie));
    }

    public function testRefreshWithoutOriginError(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);

        $response = $this->api('POST', '/auth/refresh', refreshCookie: $cookie, headers: ['Origin' => '']);

        $this->assertProblem($response, AuthError::OriginRejected);
        self::assertSame(1, $this->refreshTokenCount(), 'The token was not consumed.');
    }

    public function testRefreshWithWrongOriginError(): void
    {
        $user = $this->createVerifiedUser();
        [, $cookie] = $this->loginUser($user);

        $response = $this->api('POST', '/auth/refresh', refreshCookie: $cookie, headers: ['Origin' => 'https://evil.example']);

        $this->assertProblem($response, AuthError::OriginRejected);
        self::assertNull($this->refreshCookieValue($response));
    }
}
