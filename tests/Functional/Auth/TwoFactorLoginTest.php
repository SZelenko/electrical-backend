<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\OneTimeCode;
use App\Entity\OneTimeCodePurpose;
use App\Tests\Factory\UserFactory;

final class TwoFactorLoginTest extends AuthApiTestCase
{
    public function testTwoFactorLoginSuccess(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);

        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => strtolower($code)]);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $this->str($response, 'token'));
        $this->assertRefreshCookieAttributes($this->refreshCookieLine($response));
        self::assertNotNull($this->codeRow($user, OneTimeCodePurpose::Login2fa)?->consumedAt);
        self::assertSame(1, $this->refreshTokenCount());
        self::assertSame($user->email, $this->str($this->api('GET', '/me', bearer: $this->str($response, 'token')), 'email'));
    }

    public function testTwoFactorWrongCodeError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);

        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $this->wrongCodeFor($code)]);

        $this->assertProblem($response, AuthError::InvalidCode);
        self::assertNull($this->refreshCookieValue($response));
        self::assertSame(1, $this->codeRow($user, OneTimeCodePurpose::Login2fa)?->attempts);
        // The token is still alive: the right code works afterwards.
        self::assertSame(200, $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code])->getStatusCode());
    }

    public function testTwoFactorFifthWrongAttemptRestartsLoginError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        $wrong = $this->wrongCodeFor($code);

        for ($i = 1; $i <= 4; ++$i) {
            $this->assertProblem($this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $wrong]), AuthError::InvalidCode);
        }
        $fifth = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $wrong]);
        $this->assertProblem($fifth, AuthError::TwoFactorRestart);

        // Even the right code is dead now (per-user rate limit reset so the attempt counter is what answers).
        $this->resetRateLimiters();
        $this->assertProblem($this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code]), AuthError::TwoFactorRestart);
        self::assertSame(0, $this->refreshTokenCount());
    }

    public function testTwoFactorGarbageTokenError(): void
    {
        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => 'garbage-token', 'code' => 'ABCD1234']);

        $this->assertProblem($response, AuthError::TwoFactorRestart);
    }

    public function testTwoFactorExpiredTokenError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        $this->advanceClock(301);

        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code]);

        $this->assertProblem($response, AuthError::TwoFactorRestart);
    }

    public function testTwoFactorCodeCannotBeReusedError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        self::assertSame(200, $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code])->getStatusCode());

        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code]);

        $this->assertProblem($response, AuthError::TwoFactorRestart);
    }

    public function testTwoFactorTokenRotatedByRepeatedPasswordStepError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$oldToken, $code] = $this->startTwoFactorLogin($user);

        // Second password step within the cooldown: new token, no new email, same code.
        $second = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        self::assertSame(202, $second->getStatusCode());
        self::assertSame(0, $this->sentEmailCount());
        $newToken = $this->str($second, 'twoFactorToken');
        self::assertNotSame($oldToken, $newToken);

        $old = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $oldToken, 'code' => $code]);
        $this->assertProblem($old, AuthError::TwoFactorRestart);

        $new = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $newToken, 'code' => $code]);
        self::assertSame(200, $new->getStatusCode());
    }

    public function testTwoFactorMissingFieldsError(): void
    {
        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => 'abc']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTwoFactorRateLimitedError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        $wrong = $this->wrongCodeFor($code);
        for ($i = 0; $i < 4; ++$i) {
            $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $wrong]);
        }
        $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $wrong]);

        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code]);

        $this->assertProblem($response, AuthError::RateLimited);
        self::assertArrayHasKey('retry-after', $response->getHeaders(false));
    }

    /** Regression: a stale entity in the identity map must not hide attempts already stored in the database. */
    public function testTwoFactorStaleEntityDoesNotHideAttemptsError(): void
    {
        $this->client->getKernelBrowser()->disableReboot();
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $row = $em->getRepository(OneTimeCode::class)->findOneBy(['user' => $user->id, 'purpose' => OneTimeCodePurpose::Login2fa]);
        self::assertSame(0, $row?->attempts);
        $em->getConnection()->executeStatement('UPDATE one_time_code SET attempts = 4');

        $response = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $this->wrongCodeFor($code)]);

        $this->assertProblem($response, AuthError::TwoFactorRestart);
        self::assertSame(5, $this->scalarInt('SELECT attempts FROM one_time_code'));
    }

    public function testPasswordStepAfterConsumedCodeMailsFreshCodeSuccess(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        self::assertSame(200, $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code])->getStatusCode());

        // Within the 60 s cooldown, but the previous code is spent: a fresh one must be mailed.
        [$newToken, $newCode] = $this->startTwoFactorLogin($user);

        self::assertSame(1, $this->sentEmailCount());
        self::assertSame(200, $this->api('POST', '/auth/2fa', ['twoFactorToken' => $newToken, 'code' => $newCode])->getStatusCode());
    }

    public function testPasswordStepAfterExhaustedCodeMailsFreshCodeSuccess(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $this->wrongCodeFor($code)]);
        }
        $this->resetRateLimiters();

        [$newToken, $newCode] = $this->startTwoFactorLogin($user);

        self::assertSame(1, $this->sentEmailCount());
        self::assertSame(200, $this->api('POST', '/auth/2fa', ['twoFactorToken' => $newToken, 'code' => $newCode])->getStatusCode());
    }

    public function testTwoFactorResponsesAreNotCachedSuccess(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);

        $this->assertNoStore($this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code]));
    }
}
