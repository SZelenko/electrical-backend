<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Entity\OneTimeCode;
use App\Entity\OneTimeCodePurpose;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OneTimeCode::class)]
final class OneTimeCodeTest extends TestCase
{
    private \DateTimeImmutable $issued;

    protected function setUp(): void
    {
        $this->issued = new \DateTimeImmutable('2026-01-01 12:00:00 UTC');
    }

    private function code(OneTimeCodePurpose $purpose = OneTimeCodePurpose::EmailVerification): OneTimeCode
    {
        $user = new User('a@example.com', 'hash', $this->issued);

        return new OneTimeCode(
            $user,
            $purpose,
            str_repeat('a', 64),
            OneTimeCodePurpose::Login2fa === $purpose ? str_repeat('b', 64) : null,
            $this->issued,
        );
    }

    public function testNewCodeIsUsableUntilExpirySuccess(): void
    {
        $code = $this->code();

        self::assertSame(0, $code->attempts);
        self::assertNull($code->consumedAt);
        self::assertEquals($this->issued->modify('+10 minutes'), $code->expiresAt);
        self::assertTrue($code->isUsable($this->issued));
        self::assertTrue($code->isUsable($this->issued->modify('+599 seconds')));
    }

    public function testExpiredCodeIsNotUsableError(): void
    {
        $code = $this->code();

        self::assertFalse($code->isUsable($this->issued->modify('+600 seconds')), 'Expires exactly at expires_at.');
        self::assertFalse($code->isUsable($this->issued->modify('+1 hour')));
    }

    public function testConsumedCodeIsNotUsableError(): void
    {
        $code = $this->code();
        $code->consume($this->issued->modify('+5 seconds'));

        self::assertFalse($code->isUsable($this->issued->modify('+6 seconds')));
        self::assertEquals($this->issued->modify('+5 seconds'), $code->consumedAt);
    }

    public function testAttemptsLimitSuccess(): void
    {
        $code = $this->code();

        for ($i = 1; $i <= 4; ++$i) {
            $code->registerAttempt();
            self::assertSame($i, $code->attempts);
            self::assertTrue($code->isUsable($this->issued));
            self::assertFalse($code->isExhausted());
        }
    }

    public function testFifthAttemptExhaustsCodeError(): void
    {
        $code = $this->code();
        for ($i = 0; $i < 5; ++$i) {
            $code->registerAttempt();
        }

        self::assertTrue($code->isExhausted());
        self::assertFalse($code->isUsable($this->issued));
    }

    public function testCooldownBoundarySuccess(): void
    {
        $code = $this->code();

        self::assertTrue($code->canReissue($this->issued->modify('+60 seconds')));
        self::assertTrue($code->canReissue($this->issued->modify('+61 seconds')));
    }

    public function testCooldownBlocksEarlyReissueError(): void
    {
        $code = $this->code();

        self::assertFalse($code->canReissue($this->issued));
        self::assertFalse($code->canReissue($this->issued->modify('+59 seconds')));
    }

    public function testLoginWindowSuccess(): void
    {
        $code = $this->code(OneTimeCodePurpose::Login2fa);

        self::assertTrue($code->isLoginWindowOpen($this->issued));
        self::assertTrue($code->isLoginWindowOpen($this->issued->modify('+299 seconds')));
    }

    public function testLoginWindowClosesAfterFiveMinutesError(): void
    {
        $code = $this->code(OneTimeCodePurpose::Login2fa);

        self::assertFalse($code->isLoginWindowOpen($this->issued->modify('+300 seconds')));
        // The code itself is still alive (10 min) while the token window is closed.
        self::assertTrue($code->isUsable($this->issued->modify('+301 seconds')));
    }

    public function testReissueResetsStateSuccess(): void
    {
        $code = $this->code(OneTimeCodePurpose::Login2fa);
        $code->registerAttempt();
        $code->registerAttempt();
        $code->consume($this->issued->modify('+1 second'));
        $later = $this->issued->modify('+2 minutes');

        $code->reissue(str_repeat('c', 64), str_repeat('d', 64), $later);

        self::assertSame(0, $code->attempts);
        self::assertNull($code->consumedAt);
        self::assertSame(str_repeat('c', 64), $code->codeHash);
        self::assertSame(str_repeat('d', 64), $code->loginTokenHash);
        self::assertEquals($later, $code->issuedAt);
        self::assertEquals($later->modify('+10 minutes'), $code->expiresAt);
        self::assertTrue($code->isUsable($later));
        self::assertFalse($code->canReissue($later->modify('+30 seconds')));
    }

    public function testRotateLoginTokenKeepsCodeSuccess(): void
    {
        $code = $this->code(OneTimeCodePurpose::Login2fa);

        $code->rotateLoginToken(str_repeat('e', 64));

        self::assertSame(str_repeat('e', 64), $code->loginTokenHash);
        self::assertSame(str_repeat('a', 64), $code->codeHash);
        self::assertEquals($this->issued, $code->issuedAt);
    }
}
