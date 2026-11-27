<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use App\Entity\OneTimeCodePurpose;
use App\Tests\Factory\UserFactory;

final class LoginTest extends AuthApiTestCase
{
    public function testLoginSuccess(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->api('POST', '/auth/login', ['email' => strtoupper($user->email), 'password' => UserFactory::PASSWORD]);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $this->str($response, 'token'));
        self::assertArrayNotHasKey('refresh_token', $data);
        $line = $this->refreshCookieLine($response);
        self::assertNotSame('', (string) $this->refreshCookieValue($response));
        $this->assertRefreshCookieAttributes($line);
        self::assertSame(1, $this->refreshTokenCount());
        self::assertSame(0, $this->sentEmailCount());

        // The access token identifies the user.
        $me = $this->api('GET', '/me', bearer: $this->str($response, 'token'));
        self::assertSame($user->email, $this->json($me)['email']);
    }

    public function testLoginUnknownEmailAndWrongPasswordAreIdenticalError(): void
    {
        $user = $this->createVerifiedUser();

        $unknown = $this->api('POST', '/auth/login', ['email' => 'ghost@example.com', 'password' => UserFactory::PASSWORD]);
        $wrong = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => 'Wrong-Password-1!']);

        $this->assertProblem($unknown, AuthError::InvalidCredentials);
        $this->assertProblem($wrong, AuthError::InvalidCredentials);
        self::assertSame($this->problemCore($unknown), $this->problemCore($wrong));
        self::assertNull($this->refreshCookieValue($wrong));
        self::assertSame(0, $this->refreshTokenCount());
    }

    public function testLoginUnverifiedEmailSendsNewCodeError(): void
    {
        $user = $this->createUnverifiedUser();

        $response = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

        $this->assertProblem($response, AuthError::EmailNotVerified);
        self::assertSame(1, $this->sentEmailCount());
        self::assertSame($user->email, $this->lastEmailRecipient());
        self::assertNotNull($this->codeRow($user, OneTimeCodePurpose::EmailVerification));
        self::assertNull($this->refreshCookieValue($response));

        // The mailed code completes the verification and the next login works.
        $code = $this->mailedCode();
        self::assertSame(204, $this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => $code])->getStatusCode());
        self::assertSame(200, $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->getStatusCode());
    }

    public function testLoginUnverifiedEmailWrongPasswordDoesNotRevealAccountError(): void
    {
        $user = $this->createUnverifiedUser();

        $response = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => 'Wrong-Password-1!']);

        $this->assertProblem($response, AuthError::InvalidCredentials);
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testLoginUnverifiedEmailRespectsCooldownError(): void
    {
        $user = $this->createUnverifiedUser();
        $payload = ['email' => $user->email, 'password' => UserFactory::PASSWORD];
        $this->api('POST', '/auth/login', $payload);

        $second = $this->api('POST', '/auth/login', $payload);

        $this->assertProblem($second, AuthError::EmailNotVerified);
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testLoginWithTwoFactorSuccess(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);

        $response = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

        self::assertSame(202, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(300, $data['expiresIn']);
        self::assertGreaterThanOrEqual(40, \strlen($this->str($response, 'twoFactorToken')));
        self::assertArrayNotHasKey('token', $data);
        self::assertNull($this->refreshCookieValue($response));
        self::assertSame(1, $this->sentEmailCount());
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $this->mailedCode());
        self::assertSame(0, $this->refreshTokenCount());
        self::assertNotNull($this->codeRow($user, OneTimeCodePurpose::Login2fa)?->loginTokenHash);
    }

    public function testLoginBlankFieldsError(): void
    {
        $response = $this->api('POST', '/auth/login', ['email' => '', 'password' => '']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testLoginRateLimitedPerEmailError(): void
    {
        $payload = ['email' => 'victim@example.com', 'password' => 'Wrong-Password-1!'];
        for ($i = 0; $i < 5; ++$i) {
            $this->assertProblem($this->api('POST', '/auth/login', $payload), AuthError::InvalidCredentials);
        }

        $response = $this->api('POST', '/auth/login', $payload);

        $this->assertProblem($response, AuthError::RateLimited);
        $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(901, $retryAfter);

        // The limit is per email: another account is not affected.
        $other = $this->createVerifiedUser();
        $ok = $this->api('POST', '/auth/login', ['email' => $other->email, 'password' => UserFactory::PASSWORD]);
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testLoginResponsesAreNotCachedSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $twoFactor = $this->createVerifiedUser(twoFactor: true);

        $this->assertNoStore($this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]));
        $this->assertNoStore($this->api('POST', '/auth/login', ['email' => $twoFactor->email, 'password' => UserFactory::PASSWORD]));
    }
}
