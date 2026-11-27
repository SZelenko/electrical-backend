<?php

declare(strict_types=1);

namespace App\Entity;

enum OneTimeCodePurpose: string
{
    case EmailVerification = 'email_verification';
    case Login2fa = 'login_2fa';
}
