<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\VerifyEmail;
use App\Auth\AuthError;
use App\Auth\AuthException;
use App\Auth\AuthRateLimiter;
use App\Auth\OneTimeCodeService;
use App\Repository\UserRepository;

/** @implements ProcessorInterface<VerifyEmail, null> */
final readonly class VerifyEmailProcessor implements ProcessorInterface
{
    public function __construct(
        private AuthRateLimiter $limiter,
        private UserRepository $users,
        private OneTimeCodeService $codes,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->limiter->checkEmailVerificationIp();

        // Nothing to guess (unknown email, already verified, no live code): answer without touching the
        // per-account buckets, so an attacker cannot burn a victim's budget with such requests.
        $user = $this->users->findOneByEmail($data->email);
        if (null === $user || $user->isEmailVerified || !$this->codes->hasActiveEmailCode($user)) {
            throw new AuthException(AuthError::InvalidCode);
        }

        $this->limiter->checkEmailVerificationUser($user->id->toRfc4122());
        if (!$this->codes->verifyEmail($user, $data->code)) {
            throw new AuthException(AuthError::InvalidCode);
        }

        return null;
    }
}
