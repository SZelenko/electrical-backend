<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Me;
use App\ApiResource\Auth\TwoFactorSettings;
use App\Auth\AuthError;
use App\Auth\AuthException;
use App\Auth\AuthRateLimiter;
use App\Auth\CredentialsChecker;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/** @implements ProcessorInterface<TwoFactorSettings, Me> */
final readonly class UpdateTwoFactorProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private AuthRateLimiter $limiter,
        private CredentialsChecker $credentials,
        private EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AuthException(AuthError::TokenInvalid);
        }

        $this->limiter->checkPasswordConfirmation($user->id->toRfc4122());

        if (!$this->credentials->isPasswordValid($user, $data->password)) {
            throw new AuthException(AuthError::PasswordConfirmationFailed);
        }

        $data->enabled ? $user->enableTwoFactor() : $user->disableTwoFactor();
        $this->em->flush();

        return Me::fromUser($user);
    }
}
