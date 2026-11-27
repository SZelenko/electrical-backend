<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Auth\RegisterProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Register',
    operations: [
        new Post(
            uriTemplate: '/auth/register',
            status: 201,
            formats: ['json'],
            output: RegisteredUser::class,
            read: false,
            processor: RegisterProcessor::class,
            openapi: new Operation(
                tags: ['Auth'],
                summary: 'Creates an account (or restarts registration of an unverified one) and mails a verification code.',
            ),
        ),
    ],
)]
final class Register
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 254)]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5)]
    public string $email;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    public string $password;
}
