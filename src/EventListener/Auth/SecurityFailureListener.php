<?php

declare(strict_types=1);

namespace App\EventListener\Auth;

use App\Auth\AuthError;
use App\Auth\ProblemResponseFactory;
use Gesdinet\JWTRefreshTokenBundle\Event\RefreshAuthenticationFailureEvent;
use Gesdinet\JWTRefreshTokenBundle\Event\RefreshTokenNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Renders every Lexik / gesdinet failure (missing, invalid or expired JWT; missing, unknown or
 * expired refresh token) as the catalogue's `token_invalid` problem.
 */
final readonly class SecurityFailureListener
{
    public function __construct(private ProblemResponseFactory $problems) {}

    #[AsEventListener(event: Events::JWT_NOT_FOUND)]
    #[AsEventListener(event: Events::JWT_INVALID)]
    #[AsEventListener(event: Events::JWT_EXPIRED)]
    #[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
    public function onJwtFailure(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->problems->create(AuthError::TokenInvalid));
    }

    #[AsEventListener(event: 'gesdinet.refresh_token_failure')]
    public function onRefreshFailure(RefreshAuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->problems->create(AuthError::TokenInvalid));
    }

    #[AsEventListener(event: 'gesdinet.refresh_token_not_found')]
    public function onRefreshTokenNotFound(RefreshTokenNotFoundEvent $event): void
    {
        $event->setResponse($this->problems->create(AuthError::TokenInvalid));
    }
}
