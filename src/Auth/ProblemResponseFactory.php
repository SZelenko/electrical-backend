<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds RFC 7807 responses for errors raised outside API Platform (security listeners),
 * with the same shape API Platform renders for AuthException.
 */
final readonly class ProblemResponseFactory
{
    public function create(AuthError $error, ?int $retryAfter = null): JsonResponse
    {
        $response = new JsonResponse(
            [
                'type' => $error->value,
                'title' => $error->title(),
                'status' => $error->status(),
                'detail' => $error->title(),
            ],
            $error->status(),
            ['Content-Type' => 'application/problem+json'],
        );

        if (null !== $retryAfter) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
