<?php

declare(strict_types=1);

namespace App\EventListener\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Makes logout idempotent: always 204 and the refresh cookie is cleared, with or without a cookie.
 * Runs after gesdinet's logout listener (which deletes the token and answers 200/400 JSON), whose response it replaces.
 * Cookie attributes come from the gesdinet bundle configuration (its defaults are always present).
 */
final readonly class LogoutResponseListener
{
    /**
     * @param array{same_site: string, path: string, domain: string|null, http_only: bool, secure: bool, partitioned: bool} $cookie gesdinet_jwt_refresh_token.cookie
     */
    public function __construct(
        #[Autowire(param: 'gesdinet_jwt_refresh_token.cookie')]
        private array $cookie,
        #[Autowire(param: 'gesdinet_jwt_refresh_token.token_parameter_name')]
        private string $cookieName,
    ) {}

    #[AsEventListener(event: LogoutEvent::class, priority: -100)]
    public function onLogout(LogoutEvent $event): void
    {
        $response = new Response(null, Response::HTTP_NO_CONTENT);

        $cookie = $this->cookie;
        $response->headers->clearCookie(
            $this->cookieName,
            $cookie['path'],
            $cookie['domain'],
            $cookie['secure'],
            $cookie['http_only'],
            $cookie['same_site'],
            $cookie['partitioned'],
        );
        $response->headers->set('Cache-Control', 'no-store');

        $event->setResponse($response);
    }
}
