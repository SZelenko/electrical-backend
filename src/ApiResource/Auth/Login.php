<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\Auth\LoginProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Login',
    operations: [
        new Post(
            uriTemplate: '/auth/login',
            status: 200,
            formats: ['json'],
            output: false,
            read: false,
            processor: LoginProcessor::class,
            openapi: new OpenApiOperation(
                tags: ['Auth'],
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'Signed in. The refresh token is set as an HttpOnly cookie.',
                        content: new \ArrayObject(['application/json' => new MediaType(new \ArrayObject([
                            'type' => 'object',
                            'required' => ['token'],
                            'properties' => ['token' => ['type' => 'string', 'description' => 'Access JWT']],
                        ]))]),
                    ),
                    '202' => new OpenApiResponse(
                        description: 'Two-factor code required; a code was mailed (unless the resend cooldown applied).',
                        content: new \ArrayObject(['application/json' => new MediaType(new \ArrayObject([
                            'type' => 'object',
                            'required' => ['twoFactorToken', 'expiresIn'],
                            'properties' => [
                                'twoFactorToken' => ['type' => 'string'],
                                'expiresIn' => ['type' => 'integer', 'description' => 'Seconds'],
                            ],
                        ]))]),
                    ),
                ],
                summary: 'Signs in with email and password. 200 with a token, or 202 when two-factor authentication is enabled. Errors: invalid_credentials, email_not_verified (a new verification code is sent), rate_limited.',
            ),
        ),
    ],
)]
final class Login
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 254)]
    public string $email;

    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    public string $password;
}
