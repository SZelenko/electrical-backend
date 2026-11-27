<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues the access JWT and the refresh-token cookie after a successful login.
 *
 * Goes through Lexik's success handler: it creates the JWT and dispatches AUTHENTICATION_SUCCESS, where
 * gesdinet's listener creates/stores the refresh token and sets the cookie with the configured attributes
 * (rotating a refresh token the request may already carry).
 */
final readonly class AuthTokenIssuer
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private AuthenticationSuccessHandler $successHandler,
    ) {}

    public function issue(User $user): Response
    {
        return $this->successHandler->handleAuthenticationSuccess($user);
    }
}
