<?php

declare(strict_types=1);

namespace App\EventListener\Auth;

use App\Auth\AuthError;
use App\Auth\ProblemResponseFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The refresh cookie is sent cross-site-safe (SameSite=Strict) but refresh/logout are cookie-authenticated POSTs:
 * defence in depth against CSRF is to require an Origin header matching the allowed CORS origins.
 */
final readonly class AuthOriginListener
{
    private const string PATH_PATTERN = '#^/api/v1/auth/(refresh|logout)/?$#';

    public function __construct(
        private ProblemResponseFactory $problems,
        #[Autowire(env: 'CORS_ALLOW_ORIGIN')]
        private string $allowedOriginRegex,
    ) {}

    // Priority 20: after the router (32), before the firewall (8).
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || 1 !== preg_match(self::PATH_PATTERN, $request->getPathInfo())) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if (null === $origin || 1 !== preg_match('#' . str_replace('#', '\#', $this->allowedOriginRegex) . '#', $origin)) {
            $event->setResponse($this->problems->create(AuthError::OriginRejected));
        }
    }
}
