<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\OneTimeCode;
use App\Entity\OneTimeCodePurpose;
use App\Entity\User;
use App\Repository\OneTimeCodeRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Issuing and verifying one-time codes (email verification, 2FA login).
 *
 * Issuing is serialised per user by a PESSIMISTIC_WRITE lock on the app_user row; verifying locks the
 * code row. Failed attempts are committed before the error is raised (nothing throws inside a transaction).
 */
final readonly class OneTimeCodeService
{
    public function __construct(
        private EntityManagerInterface $em,
        private OneTimeCodeRepository $codes,
        private OtpCodec $codec,
        private AuthMailer $mailer,
        private ClockInterface $clock,
    ) {}

    /** Sends an email-verification code unless the 60 s cooldown is still running. */
    public function sendEmailVerification(User $user): void
    {
        $code = $this->issue($user, OneTimeCodePurpose::EmailVerification, null);
        if (null !== $code) {
            $this->mailer->sendVerificationCode($user->email, $code);
        }
    }

    /**
     * Password step of a 2FA login: always rotates the pending token; mails a new code unless within cooldown.
     */
    public function startLoginChallenge(User $user): LoginChallenge
    {
        $token = $this->codec->generateLoginToken();
        $code = $this->issue($user, OneTimeCodePurpose::Login2fa, $this->codec->hashLoginToken($token));
        if (null !== $code) {
            $this->mailer->sendLoginCode($user->email, $code);
        }

        return new LoginChallenge($token, OneTimeCode::LOGIN_TOKEN_WINDOW_SECONDS);
    }

    /**
     * Rotates the code of a pending 2FA login and keeps the token. Silent for unknown, dead or exhausted
     * tokens and within the cooldown. The token window slides with each resend (it is anchored to `issued_at`,
     * which a resend resets; anchoring it to the token issue needs a new column).
     */
    public function resendLoginCode(string $twoFactorToken): void
    {
        $tokenHash = $this->codec->hashLoginToken($twoFactorToken);
        $userId = $this->codes->findUserIdByLoginTokenHash($tokenHash);
        $user = null === $userId ? null : $this->em->find(User::class, $userId);
        if (null === $user) {
            return;
        }

        $code = $this->issue($user, OneTimeCodePurpose::Login2fa, null, $tokenHash);
        if (null !== $code) {
            $this->mailer->sendLoginCode($user->email, $code);
        }
    }

    /** Id of the user a pending 2FA token belongs to (rate-limit key), or null for unknown tokens. */
    public function findUserIdByLoginToken(string $twoFactorToken): ?string
    {
        return $this->codes->findUserIdByLoginTokenHash($this->codec->hashLoginToken($twoFactorToken));
    }

    /** Whether the user has an email-verification code that can still be tried (not consumed, expired or exhausted). */
    public function hasActiveEmailCode(User $user): bool
    {
        return $this->codes->hasUsableCode($user, OneTimeCodePurpose::EmailVerification, $this->clock->now());
    }

    /** True when the code was right (the email is then verified); false otherwise. Attempts are persisted. */
    public function verifyEmail(User $user, string $code): bool
    {
        return $this->em->wrapInTransaction(function () use ($user, $code): bool {
            $now = $this->clock->now();
            $row = $this->codes->findOneByUserAndPurposeForUpdate($user, OneTimeCodePurpose::EmailVerification);
            if (null === $row || !$row->isUsable($now)) {
                return false;
            }

            $row->registerAttempt();
            if (!$this->codec->codeMatches($row->codeHash, $code)) {
                return false;
            }

            $row->consume($now);
            $user->markEmailVerified($now);

            return true;
        });
    }

    /**
     * Second step of a 2FA login.
     *
     * @throws AuthException invalid_code (wrong code, attempts left) or two_factor_restart (token dead/expired/exhausted)
     */
    public function completeLogin(string $twoFactorToken, string $code): User
    {
        $hash = $this->codec->hashLoginToken($twoFactorToken);

        /** @var array{0: AuthError|null, 1: User|null} $result */
        $result = $this->em->wrapInTransaction(function () use ($hash, $code): array {
            $now = $this->clock->now();
            $row = $this->codes->findOneByLoginTokenHashForUpdate($hash);
            if (null === $row || !$row->isUsable($now) || !$row->isLoginWindowOpen($now)) {
                return [AuthError::TwoFactorRestart, null];
            }

            $row->registerAttempt();
            if ($this->codec->codeMatches($row->codeHash, $code)) {
                $row->consume($now);

                return [null, $row->user];
            }

            return [$row->isExhausted() ? AuthError::TwoFactorRestart : AuthError::InvalidCode, null];
        });

        [$error, $user] = $result;
        if (null !== $error || null === $user) {
            throw new AuthException($error ?? AuthError::TwoFactorRestart);
        }

        return $user;
    }

    /**
     * Upserts the single code row of (user, purpose) under a user-row lock; the row is re-read locked and refreshed.
     * The cooldown only protects usable rows: a consumed, expired or exhausted row is reissued at once.
     *
     * @param string|null $newLoginTokenHash   login_2fa: hash of a new pending token to store; null keeps the existing one (resend)
     * @param string|null $existingLoginTokenHash resend only: the token the caller presented; the row must still hold it
     *
     * @return string|null the plain code to mail, or null when nothing was (re)issued
     */
    private function issue(User $user, OneTimeCodePurpose $purpose, ?string $newLoginTokenHash, ?string $existingLoginTokenHash = null): ?string
    {
        return $this->em->wrapInTransaction(function () use ($user, $purpose, $newLoginTokenHash, $existingLoginTokenHash): ?string {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);

            $now = $this->clock->now();
            $row = $this->codes->findOneByUserAndPurposeForUpdate($user, $purpose);
            $isLogin = OneTimeCodePurpose::Login2fa === $purpose;

            if ($isLogin && null === $newLoginTokenHash) {
                // Resend: never revive a dead token.
                if (
                    null === $row
                    || $row->loginTokenHash !== $existingLoginTokenHash
                    || !$row->isUsable($now)
                    || !$row->isLoginWindowOpen($now)
                ) {
                    return null;
                }
            }

            if (null !== $row && $row->isUsable($now) && !$row->canReissue($now)) {
                if (null !== $newLoginTokenHash) {
                    $row->rotateLoginToken($newLoginTokenHash);
                }

                return null;
            }

            $tokenHash = $isLogin ? ($newLoginTokenHash ?? $row?->loginTokenHash) : null;
            $code = $this->codec->generateCode();
            $codeHash = $this->codec->hashCode($code);
            if (null === $row) {
                $this->em->persist(new OneTimeCode($user, $purpose, $codeHash, $tokenHash, $now));
            } else {
                $row->reissue($codeHash, $tokenHash, $now);
            }

            return $code;
        });
    }
}
