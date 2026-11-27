<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\OneTimeCodePurpose;
use App\State\Auth\ResendCodeProcessor;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ApiResource(
    shortName: 'ResendCode',
    operations: [
        new Post(
            uriTemplate: '/auth/resend-code',
            status: 202,
            formats: ['json'],
            output: false,
            read: false,
            processor: ResendCodeProcessor::class,
            openapi: new Operation(
                tags: ['Auth'],
                summary: 'Requests a new code. Always 202 (no account enumeration); `email` is required for email_verification, `twoFactorToken` for login_2fa.',
            ),
        ),
    ],
)]
final class ResendCode
{
    #[Assert\NotNull]
    public OneTimeCodePurpose $purpose;

    public ?string $email = null;

    public ?string $twoFactorToken = null;

    #[Assert\Callback]
    public function validateByPurpose(ExecutionContextInterface $context): void
    {
        if (!isset($this->purpose)) {
            return;
        }

        $validator = $context->getValidator()->inContext($context);
        if (OneTimeCodePurpose::EmailVerification === $this->purpose) {
            $validator->atPath('email')->validate($this->email, [
                new Assert\NotBlank(),
                new Assert\Length(max: 254),
                new Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5),
            ]);
        } else {
            $validator->atPath('twoFactorToken')->validate($this->twoFactorToken, [
                new Assert\NotBlank(),
                new Assert\Length(max: 128),
            ]);
        }
    }
}
