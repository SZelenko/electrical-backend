<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Entity\User;
use App\State\Auth\MeProvider;
use App\State\Auth\UpdateTwoFactorProcessor;

#[ApiResource(
    shortName: 'Me',
    operations: [
        new Get(
            uriTemplate: '/me',
            formats: ['json'],
            security: "is_granted('ROLE_USER')",
            provider: MeProvider::class,
            openapi: new OpenApiOperation(tags: ['Me'], summary: 'The current user.'),
        ),
        new Patch(
            uriTemplate: '/me/two-factor',
            formats: ['json'],
            inputFormats: ['json' => ['application/merge-patch+json', 'application/json']],
            security: "is_granted('ROLE_USER')",
            input: TwoFactorSettings::class,
            read: false,
            processor: UpdateTwoFactorProcessor::class,
            openapi: new OpenApiOperation(
                tags: ['Me'],
                summary: 'Enables or disables two-factor authentication; requires the current password.',
            ),
        ),
    ],
)]
final class Me
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $emailVerified,
        public bool $twoFactorEnabled,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self($user->id->toRfc4122(), $user->email, $user->isEmailVerified, $user->twoFactorEnabled);
    }
}
