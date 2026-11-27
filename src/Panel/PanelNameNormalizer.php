<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * Normalises a user-supplied panel name (trim, NFC) and derives the uniqueness key
 * (case-folded, so "Щит" and "щит" collide). Rejects names that are empty, longer than 100
 * characters (code points), contain control characters, or start/end with a Unicode separator
 * (e.g. NBSP) that a plain trim leaves behind.
 */
final readonly class PanelNameNormalizer
{
    public const int MAX_LENGTH = 100;

    public function normalize(string $raw, string $path = 'name'): PanelName
    {
        $violation = static fn(string $message): PanelValidationException => new PanelValidationException(
            [new Violation($path, $message)],
        );

        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw $violation('The name must be valid UTF-8.');
        }

        $name = trim($raw);
        $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
        if (!\is_string($normalized)) {
            throw $violation('The name must be valid UTF-8.');
        }
        $name = $normalized;

        if ('' === $name) {
            throw $violation('The name must not be blank.');
        }
        if (1 === preg_match('/^\p{Z}|\p{Z}$/u', $name)) {
            throw $violation('The name must not start or end with whitespace.');
        }
        if (1 === preg_match('/\p{Cc}/u', $name)) {
            throw $violation('The name must not contain control characters.');
        }
        if (mb_strlen($name) > self::MAX_LENGTH) {
            throw $violation(\sprintf('The name must be at most %d characters long.', self::MAX_LENGTH));
        }

        return new PanelName($name, mb_strtolower($name));
    }
}
