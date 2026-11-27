<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\Auth\TwoFactorLoginProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'TwoFactorLogin',
    operations: [
        new Post(
            uriTemplate: '/auth/2fa',
            status: 200,
            formats: ['json'],
            output: false,
            read: false,
            processor: TwoFactorLoginProcessor::class,
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
                ],
                summary: 'Second login step: exchanges the pending token and the mailed code for a session. Errors: invalid_code, two_factor_restart, rate_limited.',
            ),
        ),
    ],
)]
final class TwoFactorLogin
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    public string $twoFactorToken;

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    public string $code;
}
