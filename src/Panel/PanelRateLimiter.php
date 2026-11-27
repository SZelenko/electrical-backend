<?php

declare(strict_types=1);

namespace App\Panel;

use App\Auth\AuthError;
use App\Auth\AuthException;
use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-user limits of the panel endpoints (saving the content, reading one panel). A refusal is the
 * auth catalogue's `rate_limited` problem: 429 with `Retry-After`.
 */
final readonly class PanelRateLimiter
{
    public function __construct(
        private RateLimiterFactoryInterface $panelSaveUserLimiter,
        private RateLimiterFactoryInterface $panelReadUserLimiter,
        private ClockInterface $clock,
    ) {}

    public function checkSave(User $owner): void
    {
        $this->consume($this->panelSaveUserLimiter, $owner);
    }

    public function checkRead(User $owner): void
    {
        $this->consume($this->panelReadUserLimiter, $owner);
    }

    private function consume(RateLimiterFactoryInterface $factory, User $owner): void
    {
        $limit = $factory->create($owner->id->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            throw new AuthException(AuthError::RateLimited, max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()));
        }
    }
}
