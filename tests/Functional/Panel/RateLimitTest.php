<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Entity\User;
use App\Tests\Support\PanelContentBuilder as B;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Per-user limits: saving the content 60/min, reading one panel 300/min (429 `rate_limited`). */
final class RateLimitTest extends PanelApiTestCase
{
    public function testSavesWithinTheLimitSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(200, $this->putContent($user, $panel['id'], B::minimal())->getStatusCode());
        }
    }

    public function testSaveOverTheLimitError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->exhaust('limiter.panel_save_user', $user, 60);

        $response = $this->putContent($user, $panel['id'], B::minimal());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('rate_limited', $this->json($response)['type']);
        self::assertGreaterThanOrEqual(1, (int) ($response->getHeaders(false)['retry-after'][0] ?? 0));
        self::assertSame(1, $this->panelRow($panel['id'])?->version, 'The refused save wrote nothing.');
    }

    public function testSaveLimitIsPerUserSuccess(): void
    {
        $busy = $this->createVerifiedUser();
        $other = $this->createVerifiedUser();
        $panel = $this->createPanel($other);
        $this->exhaust('limiter.panel_save_user', $busy, 60);

        self::assertSame(200, $this->putContent($other, $panel['id'], B::minimal())->getStatusCode());
    }

    public function testReadWithinTheLimitSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        self::assertSame(200, $this->panelApi('GET', '/panels/' . $panel['id'], $user)->getStatusCode());
    }

    public function testReadOverTheLimitError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->exhaust('limiter.panel_read_user', $user, 300);

        $response = $this->panelApi('GET', '/panels/' . $panel['id'], $user);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('rate_limited', $this->json($response)['type']);
        self::assertArrayHasKey('retry-after', $response->getHeaders(false));
    }

    public function testReadLimitDoesNotBlockListsOrSavesSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->exhaust('limiter.panel_read_user', $user, 300);

        self::assertSame(200, $this->panelApi('GET', '/panels', $user)->getStatusCode());
        self::assertSame(200, $this->putContent($user, $panel['id'], B::minimal())->getStatusCode());
    }

    public function testReadLimitStillBlocksItemReadsError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->exhaust('limiter.panel_read_user', $user, 300);

        self::assertSame(429, $this->panelApi('GET', '/panels/' . $panel['id'], $user)->getStatusCode());
    }

    private function exhaust(string $serviceId, User $user, int $tokens): void
    {
        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get($serviceId);
        $limit = $factory->create($user->id->toRfc4122())->consume($tokens);
        self::assertTrue($limit->isAccepted());
    }
}
