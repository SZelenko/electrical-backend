<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Auth\VerifyEmailProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'VerifyEmail',
    operations: [
        new Post(
            uriTemplate: '/auth/verify-email',
            status: 204,
            formats: ['json'],
            output: false,
            read: false,
            processor: VerifyEmailProcessor::class,
            openapi: new Operation(
                tags: ['Auth'],
                summary: 'Confirms the email address with the code received by mail.',
            ),
        ),
    ],
)]
final class VerifyEmail
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 254)]
    public string $email;

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    public string $code;
}
