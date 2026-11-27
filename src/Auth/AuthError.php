<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Catalogue of authentication problems; the backing value is the RFC 7807 `type`.
 */
enum AuthError: string
{
    case InvalidCredentials = 'invalid_credentials';
    case EmailNotVerified = 'email_not_verified';
    case InvalidCode = 'invalid_code';
    case TwoFactorRestart = 'two_factor_restart';
    case RateLimited = 'rate_limited';
    case TokenInvalid = 'token_invalid';
    case PasswordConfirmationFailed = 'password_confirmation_failed';
    case OriginRejected = 'origin_rejected';

    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials, self::TwoFactorRestart, self::TokenInvalid => 401,
            self::EmailNotVerified, self::PasswordConfirmationFailed, self::OriginRejected => 403,
            self::InvalidCode => 422,
            self::RateLimited => 429,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::InvalidCredentials => 'Invalid credentials.',
            self::EmailNotVerified => 'Email address is not verified.',
            self::InvalidCode => 'Invalid or expired code.',
            self::TwoFactorRestart => 'Two-factor login must be restarted.',
            self::RateLimited => 'Too many requests.',
            self::TokenInvalid => 'Missing, expired or invalid token.',
            self::PasswordConfirmationFailed => 'Password confirmation failed.',
            self::OriginRejected => 'Request origin is not allowed.',
        };
    }
}
