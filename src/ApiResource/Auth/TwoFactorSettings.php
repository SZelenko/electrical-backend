<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class TwoFactorSettings
{
    #[Assert\NotNull]
    public bool $enabled;

    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    public string $password;
}
