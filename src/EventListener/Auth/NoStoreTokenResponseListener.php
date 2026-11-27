<?php

declare(strict_types=1);

namespace App\EventListener\Auth;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Responses that carry an access token must never be cached. */
final readonly class NoStoreTokenResponseListener
{
    private const string PATH_PATTERN = '#^/api/v1/auth/(login|2fa|refresh)/?$#';

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && 1 === preg_match(self::PATH_PATTERN, $event->getRequest()->getPathInfo())) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store');
        }
    }
}
