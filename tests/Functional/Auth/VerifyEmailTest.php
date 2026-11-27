<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use App\Entity\OneTimeCodePurpose;

final class VerifyEmailTest extends AuthApiTestCase
{
    private const string PASSWORD = 'Hy9#mQ2$vLx8-Panel';

    /** @return array{0: string, 1: string} email and the mailed code */
    private function register(string $email = 'verify@example.com'): array
    {
        $this->api('POST', '/auth/register', ['email' => $email, 'password' => self::PASSWORD]);

        return [$email, $this->mailedCode()];
    }

    public function testVerifyEmailSuccess(): void
    {
        [$email, $code] = $this->register();

        $response = $this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $code]);

        self::assertSame(204, $response->getStatusCode());
        $user = $this->findUserByEmail($email);
        self::assertNotNull($user);
        self::assertTrue($user->isEmailVerified);
        self::assertNotNull($this->codeRow($user, OneTimeCodePurpose::EmailVerification)?->consumedAt);

        $login = $this->api('POST', '/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(200, $login->getStatusCode());
    }

    public function testVerifyEmailLowercaseCodeAndPaddingSuccess(): void
    {
        [$email, $code] = $this->register();

        $response = $this->api('POST', '/auth/verify-email', ['email' => ' ' . strtoupper($email) . ' ', 'code' => ' ' . strtolower($code) . ' ']);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testVerifyEmailWrongCodeError(): void
    {
        [$email, $code] = $this->register();

        $response = $this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $this->wrongCodeFor($code)]);

        $this->assertProblem($response, AuthError::InvalidCode);
        $user = $this->findUserByEmail($email);
        self::assertFalse($user?->isEmailVerified);
        self::assertSame(1, $this->codeRow($user, OneTimeCodePurpose::EmailVerification)?->attempts);
    }

    public function testVerifyEmailUnknownEmailError(): void
    {
        $known = $this->api('POST', '/auth/verify-email', ['email' => 'ghost@example.com', 'code' => 'ABCD1234']);
        [$email, $code] = $this->register();
        $wrong = $this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $this->wrongCodeFor($code)]);

        $this->assertProblem($known, AuthError::InvalidCode);
        // Same body for unknown email and wrong code: no account enumeration.
        self::assertSame($this->problemCore($wrong), $this->problemCore($known));
    }

    public function testVerifyEmailNoCodeIssuedError(): void
    {
        $user = $this->createUnverifiedUser();

        $response = $this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => 'ABCD1234']);

        $this->assertProblem($response, AuthError::InvalidCode);
    }

    public function testVerifyEmailAfterFiveWrongAttemptsRightCodeIsRejectedError(): void
    {
        [$email, $code] = $this->register();
        $wrong = $this->wrongCodeFor($code);

        for ($i = 0; $i < 5; ++$i) {
            $this->assertProblem($this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $wrong]), AuthError::InvalidCode);
        }
        // The per-user rate limit (5 per 15 min) would answer 429 first; reset it to test the attempt counter itself.
        $this->resetRateLimiters();

        $response = $this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $code]);

        $this->assertProblem($response, AuthError::InvalidCode);
        $user = $this->findUserByEmail($email);
        self::assertFalse($user?->isEmailVerified);
        self::assertSame(5, $this->codeRow($user, OneTimeCodePurpose::EmailVerification)?->attempts);
    }

    public function testVerifyEmailExpiredCodeError(): void
    {
        [$email, $code] = $this->register();
        $this->advanceClock(601);

        $response = $this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $code]);

        $this->assertProblem($response, AuthError::InvalidCode);
    }

    public function testVerifyEmailCodeCannotBeReusedError(): void
    {
        [$email, $code] = $this->register();
        self::assertSame(204, $this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $code])->getStatusCode());

        $response = $this->api('POST', '/auth/verify-email', ['email' => $email, 'code' => $code]);

        $this->assertProblem($response, AuthError::InvalidCode);
    }

    public function testVerifyEmailMissingFieldsError(): void
    {
        $response = $this->api('POST', '/auth/verify-email', ['email' => 'x@example.com']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testVerifyEmailRateLimitedPerUserError(): void
    {
        $user = $this->createUnverifiedUser();
        $code = $this->issueVerificationCode($user);
        $wrong = $this->wrongCodeFor($code);
        for ($i = 0; $i < 5; ++$i) {
            $this->assertProblem($this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => $wrong]), AuthError::InvalidCode);
        }

        // A single code is exhausted after 5 tries (invalid_code, no bucket spent); a fresh code makes the 6th
        // try within the window hit the per-user bucket.
        $this->issueVerificationCode($user);
        $response = $this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => $wrong]);

        $this->assertProblem($response, AuthError::RateLimited);
        self::assertArrayHasKey('retry-after', $response->getHeaders(false));
    }

    public function testVerifyEmailAlreadyVerifiedDoesNotConsumeUserBucketSuccess(): void
    {
        $verified = $this->createVerifiedUser();
        for ($i = 0; $i < 8; ++$i) {
            $this->assertProblem($this->api('POST', '/auth/verify-email', ['email' => $verified->email, 'code' => 'ABCD1234']), AuthError::InvalidCode);
        }
        self::assertSame(0, $this->sentEmailCount());

        // Same IP, bucket keyed by IP|user: nothing was spent for the verified user (invalid_code, never 429).
        $this->assertProblem($this->api('POST', '/auth/verify-email', ['email' => $verified->email, 'code' => 'ABCD1234']), AuthError::InvalidCode);
    }

    public function testVerifyEmailWithoutLiveCodeDoesNotConsumeUserBucketSuccess(): void
    {
        $user = $this->createUnverifiedUser();
        for ($i = 0; $i < 8; ++$i) {
            $this->assertProblem($this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => 'ABCD1234']), AuthError::InvalidCode);
        }

        // The bucket is untouched: the first real code is still verifiable.
        $code = $this->issueVerificationCode($user);
        self::assertSame(204, $this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => $code])->getStatusCode());
    }
}
