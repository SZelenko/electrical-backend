<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use App\Entity\OneTimeCodePurpose;

final class ResendCodeTest extends AuthApiTestCase
{
    public function testResendEmailVerificationCodeSuccess(): void
    {
        $user = $this->createUnverifiedUser();

        $response = $this->api('POST', '/auth/resend-code', ['purpose' => 'email_verification', 'email' => $user->email]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(1, $this->sentEmailCount());
        self::assertSame($user->email, $this->lastEmailRecipient());
        self::assertNotNull($this->codeRow($user, OneTimeCodePurpose::EmailVerification));
    }

    public function testResendEmailVerificationCooldownSuccess(): void
    {
        $user = $this->createUnverifiedUser();
        $payload = ['purpose' => 'email_verification', 'email' => $user->email];
        $this->api('POST', '/auth/resend-code', $payload);
        $firstCode = $this->mailedCode();

        $within = $this->api('POST', '/auth/resend-code', $payload);
        self::assertSame(202, $within->getStatusCode());
        self::assertSame(0, $this->sentEmailCount(), 'No second email within the 60 s cooldown.');

        $this->advanceClock(61);
        $after = $this->api('POST', '/auth/resend-code', $payload);
        self::assertSame(202, $after->getStatusCode());
        self::assertSame(1, $this->sentEmailCount());

        // The reissue overwrote the previous code.
        $newCode = $this->mailedCode();
        $wrong = $this->api('POST', '/auth/verify-email', ['email' => $user->email, 'code' => $firstCode]);
        if ($firstCode !== $newCode) {
            $this->assertProblem($wrong, AuthError::InvalidCode);
        }
    }

    public function testResendUnknownEmailStillAcceptedError(): void
    {
        $response = $this->api('POST', '/auth/resend-code', ['purpose' => 'email_verification', 'email' => 'ghost@example.com']);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testResendVerifiedAccountStillAcceptedError(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->api('POST', '/auth/resend-code', ['purpose' => 'email_verification', 'email' => $user->email]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testResendTwoFactorCodeSuccess(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $firstCode] = $this->startTwoFactorLogin($user);

        $within = $this->api('POST', '/auth/resend-code', ['purpose' => 'login_2fa', 'twoFactorToken' => $token]);
        self::assertSame(202, $within->getStatusCode());
        self::assertSame(0, $this->sentEmailCount(), 'Cooldown applies to the 2FA code too.');

        $this->advanceClock(61);
        $after = $this->api('POST', '/auth/resend-code', ['purpose' => 'login_2fa', 'twoFactorToken' => $token]);
        self::assertSame(202, $after->getStatusCode());
        self::assertSame(1, $this->sentEmailCount());
        $newCode = $this->mailedCode();

        // The token stays valid and accepts the new code.
        $done = $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $newCode]);
        self::assertSame(200, $done->getStatusCode());
        self::assertNotSame('', $firstCode);
    }

    public function testResendTwoFactorGarbageTokenStillAcceptedError(): void
    {
        $response = $this->api('POST', '/auth/resend-code', ['purpose' => 'login_2fa', 'twoFactorToken' => 'does-not-exist']);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testResendTwoFactorExpiredTokenWindowSendsNothingError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token] = $this->startTwoFactorLogin($user);
        $this->advanceClock(301);

        $response = $this->api('POST', '/auth/resend-code', ['purpose' => 'login_2fa', 'twoFactorToken' => $token]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testResendMalformedRequestError(): void
    {
        $noPurpose = $this->api('POST', '/auth/resend-code', ['email' => 'a@example.com']);
        $noEmail = $this->api('POST', '/auth/resend-code', ['purpose' => 'email_verification']);
        $noToken = $this->api('POST', '/auth/resend-code', ['purpose' => 'login_2fa']);
        $badPurpose = $this->api('POST', '/auth/resend-code', ['purpose' => 'nope', 'email' => 'a@example.com']);

        self::assertSame(422, $noPurpose->getStatusCode());
        self::assertSame(422, $noEmail->getStatusCode());
        self::assertContains('email', $this->violationPaths($noEmail));
        self::assertSame(422, $noToken->getStatusCode());
        self::assertContains('twoFactorToken', $this->violationPaths($noToken));
        self::assertSame(422, $badPurpose->getStatusCode());
    }

    public function testResendRateLimitedPerEmailError(): void
    {
        $payload = ['purpose' => 'email_verification', 'email' => 'ghost@example.com'];
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(202, $this->api('POST', '/auth/resend-code', $payload)->getStatusCode());
        }

        $response = $this->api('POST', '/auth/resend-code', $payload);

        $this->assertProblem($response, AuthError::RateLimited);
        self::assertArrayHasKey('retry-after', $response->getHeaders(false));
    }

    public function testResendDoesNotReviveConsumedTwoFactorTokenError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        self::assertSame(200, $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code])->getStatusCode());
        $this->advanceClock(61);

        $resend = $this->api('POST', '/auth/resend-code', ['purpose' => 'login_2fa', 'twoFactorToken' => $token]);

        self::assertSame(202, $resend->getStatusCode());
        self::assertSame(0, $this->sentEmailCount());
        $this->assertProblem($this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code]), AuthError::TwoFactorRestart);
    }

    public function testResendDoesNotReviveExhaustedTwoFactorTokenError(): void
    {
        $user = $this->createVerifiedUser(twoFactor: true);
        [$token, $code] = $this->startTwoFactorLogin($user);
        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $this->wrongCodeFor($code)]);
        }
        $this->resetRateLimiters();
        $this->advanceClock(61);

        $resend = $this->api('POST', '/auth/resend-code', ['purpose' => 'login_2fa', 'twoFactorToken' => $token]);

        self::assertSame(202, $resend->getStatusCode());
        self::assertSame(0, $this->sentEmailCount());
        $this->assertProblem($this->api('POST', '/auth/2fa', ['twoFactorToken' => $token, 'code' => $code]), AuthError::TwoFactorRestart);
    }
}
