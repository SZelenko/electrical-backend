<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\ResendCode;
use App\Auth\AuthRateLimiter;
use App\Auth\OneTimeCodeService;
use App\Entity\OneTimeCodePurpose;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Always answers 202; mails only when the account (or pending login) exists and the cooldown has passed.
 *
 * @implements ProcessorInterface<ResendCode, null>
 */
final readonly class ResendCodeProcessor implements ProcessorInterface
{
    public function __construct(
        private AuthRateLimiter $limiter,
        private UserRepository $users,
        private OneTimeCodeService $codes,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        if (OneTimeCodePurpose::EmailVerification === $data->purpose) {
            $email = User::normalizeEmail((string) $data->email);
            $this->limiter->checkCodeResend($email);

            $user = $this->users->findOneByEmail($email);
            if (null !== $user && !$user->isEmailVerified) {
                $this->codes->sendEmailVerification($user);
            }

            return null;
        }

        $token = (string) $data->twoFactorToken;
        $userId = $this->codes->findUserIdByLoginToken($token);
        $this->limiter->checkCodeResend(null !== $userId ? 'user:' . $userId : 'token:' . hash('sha256', $token));

        if (null !== $userId) {
            $this->codes->resendLoginCode($token);
        }

        return null;
    }
}
