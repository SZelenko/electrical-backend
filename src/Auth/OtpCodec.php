<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generation, normalisation and hashing of one-time codes and pending 2FA login tokens.
 */
final readonly class OtpCodec
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    public const int CODE_LENGTH = 8;

    public function __construct(
        #[Autowire(env: 'OTP_HMAC_KEY')]
        #[\SensitiveParameter]
        private string $hmacKey,
    ) {
        if ('' === $hmacKey) {
            throw new \LogicException('The OTP_HMAC_KEY environment variable must not be empty.');
        }
    }

    public function generateCode(): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** HMAC-SHA256 of the normalised code, lowercase hex (64 chars). */
    public function hashCode(string $code): string
    {
        return hash_hmac('sha256', self::normalizeCode($code), $this->hmacKey);
    }

    public function codeMatches(string $storedHash, string $submittedCode): bool
    {
        return hash_equals($storedHash, $this->hashCode($submittedCode));
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** 32 random bytes, base64url without padding. */
    public function generateLoginToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hashLoginToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
