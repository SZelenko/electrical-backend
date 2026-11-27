<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\TwoFactorLogin;
use App\Auth\AuthRateLimiter;
use App\Auth\AuthTokenIssuer;
use App\Auth\OneTimeCodeService;
use Symfony\Component\HttpFoundation\Response;

/** @implements ProcessorInterface<TwoFactorLogin, Response> */
final readonly class TwoFactorLoginProcessor implements ProcessorInterface
{
    public function __construct(
        private AuthRateLimiter $limiter,
        private OneTimeCodeService $codes,
        private AuthTokenIssuer $tokens,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $userId = $this->codes->findUserIdByLoginToken($data->twoFactorToken);
        $this->limiter->checkTwoFactor($userId ?? 'token:' . hash('sha256', $data->twoFactorToken));

        return $this->tokens->issue($this->codes->completeLogin($data->twoFactorToken, $data->code));
    }
}
