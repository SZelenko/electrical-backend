<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Auth\Me;
use App\Auth\AuthError;
use App\Auth\AuthException;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/** @implements ProviderInterface<Me> */
final readonly class MeProvider implements ProviderInterface
{
    public function __construct(private Security $security) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AuthException(AuthError::TokenInvalid);
        }

        return Me::fromUser($user);
    }
}
