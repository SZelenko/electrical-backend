<?php

declare(strict_types=1);

namespace App\Auth;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Domain exception for every authentication error in the catalogue (see AuthError).
 * API Platform renders it as RFC 7807 with `type` = the error code and copies getHeaders() (Retry-After).
 */
final class AuthException extends \RuntimeException implements ProblemExceptionInterface, HttpExceptionInterface
{
    public function __construct(
        public readonly AuthError $error,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($error->title());
    }

    public function getType(): string
    {
        return $this->error->value;
    }

    public function getTitle(): string
    {
        return $this->error->title();
    }

    public function getStatus(): int
    {
        return $this->error->status();
    }

    public function getDetail(): string
    {
        return $this->error->title();
    }

    public function getInstance(): ?string
    {
        return null;
    }

    public function getStatusCode(): int
    {
        return $this->error->status();
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return null === $this->retryAfter ? [] : ['Retry-After' => (string) $this->retryAfter];
    }
}
