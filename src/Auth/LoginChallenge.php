<?php

declare(strict_types=1);

namespace App\Auth;

final readonly class LoginChallenge
{
    public function __construct(
        public string $twoFactorToken,
        public int $expiresIn,
    ) {}
}
