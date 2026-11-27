<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Applies the limiters of the auth endpoints; throws AuthException (429, Retry-After). Every limiter of a check must allow.
 *
 * Per-subject buckets are keyed by (IP + subject) so one client cannot lock a victim out; a looser global
 * per-subject bucket still caps distributed guessing against a single account.
 */
final readonly class AuthRateLimiter
{
    public function __construct(
        private RequestStack $requestStack,
        private RateLimiterFactoryInterface $authLoginIpLimiter,
        private RateLimiterFactoryInterface $authLoginEmailLimiter,
        private RateLimiterFactoryInterface $authLoginEmailGlobalLimiter,
        private RateLimiterFactoryInterface $authVerifyEmailIpLimiter,
        private RateLimiterFactoryInterface $authVerifyEmailUserLimiter,
        private RateLimiterFactoryInterface $auth2faIpLimiter,
        private RateLimiterFactoryInterface $auth2faUserLimiter,
        private RateLimiterFactoryInterface $authCodeVerifyUserGlobalLimiter,
        private RateLimiterFactoryInterface $authCodeResendIpLimiter,
        private RateLimiterFactoryInterface $authCodeResendEmailLimiter,
        private RateLimiterFactoryInterface $authRegisterIpLimiter,
        private RateLimiterFactoryInterface $authPasswordConfirmLimiter,
    ) {}

    public function checkLogin(string $normalizedEmail): void
    {
        $ip = $this->ip();
        $this->consume([
            [$this->authLoginIpLimiter, $ip],
            [$this->authLoginEmailLimiter, $ip . '|' . $normalizedEmail],
            [$this->authLoginEmailGlobalLimiter, $normalizedEmail],
        ]);
    }

    public function checkEmailVerificationIp(): void
    {
        $this->consume([[$this->authVerifyEmailIpLimiter, $this->ip()]]);
    }

    /** Only for accounts that have a live code to guess. */
    public function checkEmailVerificationUser(string $userId): void
    {
        $this->consume([
            [$this->authVerifyEmailUserLimiter, $this->ip() . '|' . $userId],
            [$this->authCodeVerifyUserGlobalLimiter, $userId],
        ]);
    }

    /** @param string $subject user id when the token is known, otherwise a digest of the token */
    public function checkTwoFactor(string $subject): void
    {
        $ip = $this->ip();
        $this->consume([
            [$this->auth2faIpLimiter, $ip],
            [$this->auth2faUserLimiter, $ip . '|' . $subject],
            [$this->authCodeVerifyUserGlobalLimiter, $subject],
        ]);
    }

    /** @param string $subject normalised email, or a digest of the pending 2FA token */
    public function checkCodeResend(string $subject): void
    {
        $this->consume([
            [$this->authCodeResendIpLimiter, $this->ip()],
            [$this->authCodeResendEmailLimiter, $subject],
        ]);
    }

    public function checkRegistration(): void
    {
        $this->consume([[$this->authRegisterIpLimiter, $this->ip()]]);
    }

    public function checkPasswordConfirmation(string $userId): void
    {
        $this->consume([[$this->authPasswordConfirmLimiter, $userId]]);
    }

    /** @param list<array{0: RateLimiterFactoryInterface, 1: string}> $checks */
    private function consume(array $checks): void
    {
        foreach ($checks as [$factory, $key]) {
            $limit = $factory->create($key)->consume();
            if (!$limit->isAccepted()) {
                throw new AuthException(
                    AuthError::RateLimited,
                    max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                );
            }
        }
    }

    private function ip(): string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
    }
}
