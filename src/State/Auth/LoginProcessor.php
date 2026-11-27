<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Login;
use App\Auth\AuthError;
use App\Auth\AuthException;
use App\Auth\AuthRateLimiter;
use App\Auth\AuthTokenIssuer;
use App\Auth\CredentialsChecker;
use App\Auth\OneTimeCodeService;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** @implements ProcessorInterface<Login, Response> */
final readonly class LoginProcessor implements ProcessorInterface
{
    public function __construct(
        private AuthRateLimiter $limiter,
        private CredentialsChecker $credentials,
        private OneTimeCodeService $codes,
        private AuthTokenIssuer $tokens,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $this->limiter->checkLogin(User::normalizeEmail($data->email));

        $user = $this->credentials->authenticate($data->email, $data->password)
            ?? throw new AuthException(AuthError::InvalidCredentials);

        if (!$user->isEmailVerified) {
            $this->codes->sendEmailVerification($user);

            throw new AuthException(AuthError::EmailNotVerified);
        }

        if ($user->twoFactorEnabled) {
            $challenge = $this->codes->startLoginChallenge($user);

            return new JsonResponse(
                ['twoFactorToken' => $challenge->twoFactorToken, 'expiresIn' => $challenge->expiresIn],
                Response::HTTP_ACCEPTED,
                ['Cache-Control' => 'no-store'],
            );
        }

        return $this->tokens->issue($user);
    }
}
