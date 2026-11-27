<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Register;
use App\ApiResource\Auth\RegisteredUser;
use App\Auth\AuthRateLimiter;
use App\Auth\RegistrationService;

/** @implements ProcessorInterface<Register, RegisteredUser> */
final readonly class RegisterProcessor implements ProcessorInterface
{
    public function __construct(
        private AuthRateLimiter $limiter,
        private RegistrationService $registration,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RegisteredUser
    {
        $this->limiter->checkRegistration();

        $user = $this->registration->register($data->email, $data->password);

        return new RegisteredUser($user->id->toRfc4122(), $user->email);
    }
}
