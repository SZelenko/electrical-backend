<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Test\Client;
use ApiPlatform\Test\ApiTestCase;
use App\Auth\AuthError;
use App\Entity\OneTimeCode;
use App\Entity\OneTimeCodePurpose;
use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared helpers of the auth functional tests: JSON requests, refresh cookie handling,
 * one-time codes read from the mailed message, clock control and a clean rate-limiter pool.
 */
abstract class AuthApiTestCase extends ApiTestCase
{
    use ClockSensitiveTrait;
    use MailerAssertionsTrait;

    protected const string BASE = '/api/v1';
    protected const string ORIGIN = 'https://app.electrical.local';

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime();
        $this->client = static::createClient();
        $this->resetRateLimiters();
    }

    protected function tearDown(): void
    {
        self::mockTime(false);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $body    JSON body; null sends none, [] sends `{}`
     * @param array<string, string>     $headers
     */
    protected function api(
        string $method,
        string $path,
        ?array $body = null,
        ?string $bearer = null,
        ?string $refreshCookie = null,
        array $headers = [],
    ): ResponseInterface {
        $headers += ['Accept' => 'application/json', 'Origin' => self::ORIGIN];
        if ('' === $headers['Origin']) {
            unset($headers['Origin']); // '' means: send no Origin header
        }
        if (null !== $bearer) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        $options = ['headers' => $headers];
        if (null !== $body) {
            $headers += ['Content-Type' => 'application/json'];
            $options['headers'] = $headers;
            $options['body'] = json_encode([] === $body ? new \stdClass() : $body, JSON_THROW_ON_ERROR);
        }

        // Only the cookie given to this call is sent: the jar is emptied first. A `Cookie` header is
        // overridden by the browser's jar, and the cookie is `Secure` while the test client speaks http,
        // so the value is placed in the jar as a plain cookie.
        $jar = $this->client->getKernelBrowser()->getCookieJar();
        $jar->clear();
        if (null !== $refreshCookie) {
            $jar->set(new Cookie('refresh_token', $refreshCookie));
        }

        return $this->client->request($method, self::BASE . $path, $options);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
    }

    /** A string field of the JSON body. */
    protected function str(ResponseInterface $response, string $key): string
    {
        $value = $this->json($response)[$key] ?? null;
        self::assertIsString($value, sprintf('Field "%s" is not a string.', $key));

        return $value;
    }

    /** @return list<string> property paths of the 422 violations */
    protected function violationPaths(ResponseInterface $response): array
    {
        $violations = $this->json($response)['violations'] ?? [];
        self::assertIsArray($violations);

        return array_values(array_map(
            static fn(mixed $v): string => \is_array($v) && \is_string($v['propertyPath'] ?? null) ? $v['propertyPath'] : '',
            $violations,
        ));
    }

    /**
     * The stable part of a problem body (debug mode adds traces to the full body).
     *
     * @return array<string, mixed>
     */
    protected function problemCore(ResponseInterface $response): array
    {
        return array_intersect_key($this->json($response), array_flip(['type', 'title', 'status', 'detail']));
    }

    /** Makes the server mail a fresh email-verification code to the (unverified) user and returns it. */
    protected function issueVerificationCode(User $user): string
    {
        $response = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        $this->assertProblem($response, AuthError::EmailNotVerified);

        return $this->mailedCode();
    }

    protected function assertNoStore(ResponseInterface $response): void
    {
        self::assertStringContainsString('no-store', $response->getHeaders(false)['cache-control'][0] ?? '');
    }

    protected function createVerifiedUser(bool $twoFactor = false): User
    {
        $factory = UserFactory::new()->verified();
        if ($twoFactor) {
            $factory = $factory->withTwoFactor();
        }

        return $factory->create();
    }

    protected function createUnverifiedUser(): User
    {
        return UserFactory::createOne();
    }

    protected function bearerFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        return $manager->create($user);
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        return $em;
    }

    protected function reloadUser(User $user): User
    {
        return $this->em()->find(User::class, $user->id) ?? throw new \LogicException('User vanished.');
    }

    protected function findUserByEmail(string $email): ?User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    protected function codeRow(User $user, OneTimeCodePurpose $purpose): ?OneTimeCode
    {
        return $this->em()->getRepository(OneTimeCode::class)->findOneBy(['user' => $user->id, 'purpose' => $purpose]);
    }

    protected function refreshTokenCount(): int
    {
        return $this->scalarInt('SELECT COUNT(*) FROM refresh_token');
    }

    protected function scalarInt(string $sql): int
    {
        $value = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    protected function resetRateLimiters(): void
    {
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function advanceClock(int $seconds): void
    {
        Clock::get()->sleep($seconds);
    }

    /** Number of emails sent while handling the most recent request. */
    protected function sentEmailCount(): int
    {
        return \count(self::getMailerMessages());
    }

    /** The one-time code in the body of the most recent email (the one sent while handling the last request). */
    protected function mailedCode(): string
    {
        $messages = self::getMailerMessages();
        self::assertNotEmpty($messages, 'No email was sent.');
        $message = end($messages);
        self::assertInstanceOf(Email::class, $message);
        $matches = [];
        self::assertSame(1, preg_match('/\b([A-Z0-9]{8})\b/', (string) $message->getTextBody(), $matches), 'No code in the email body.');
        $code = $matches[1] ?? null;
        self::assertIsString($code);

        return $code;
    }

    protected function lastEmailRecipient(): string
    {
        $messages = self::getMailerMessages();
        $message = end($messages);
        self::assertInstanceOf(Email::class, $message);

        return $message->getTo()[0]->getAddress();
    }

    /** A code that is certainly not the given one. */
    protected function wrongCodeFor(string $code): string
    {
        return 'A' === $code[0] ? 'B' . substr($code, 1) : 'A' . substr($code, 1);
    }

    protected function refreshCookieValue(ResponseInterface $response): ?string
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $line) {
            if (str_starts_with($line, 'refresh_token=')) {
                return explode(';', substr($line, \strlen('refresh_token=')), 2)[0];
            }
        }

        return null;
    }

    protected function refreshCookieLine(ResponseInterface $response): string
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $line) {
            if (str_starts_with($line, 'refresh_token=')) {
                return $line;
            }
        }

        self::fail('No refresh_token Set-Cookie header.');
    }

    protected function assertRefreshCookieAttributes(string $line): void
    {
        $lower = strtolower($line);
        self::assertStringContainsString('httponly', $lower);
        self::assertStringContainsString('; secure', $lower);
        self::assertStringContainsString('samesite=strict', $lower);
        self::assertStringContainsString('path=/api/v1/auth', $lower);
    }

    /** Asserts an RFC 7807 problem of the auth catalogue. */
    protected function assertProblem(ResponseInterface $response, AuthError $error): void
    {
        self::assertSame($error->status(), $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaders(false)['content-type'][0] ?? '');
        $data = $this->json($response);
        self::assertSame($error->value, $data['type'] ?? null);
        self::assertSame($error->status(), $data['status'] ?? null);
        self::assertSame($error->title(), $data['title'] ?? null);
        self::assertArrayHasKey('detail', $data);
    }

    /**
     * Password step of a 2FA login; returns the pending token and the mailed code.
     *
     * @return array{0: string, 1: string}
     */
    protected function startTwoFactorLogin(User $user): array
    {
        $response = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        self::assertSame(202, $response->getStatusCode());

        return [$this->str($response, 'twoFactorToken'), $this->mailedCode()];
    }

    /**
     * Full password login of a user without 2FA.
     *
     * @return array{0: string, 1: string} access token and refresh cookie value
     */
    protected function loginUser(User $user): array
    {
        $response = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        self::assertSame(200, $response->getStatusCode());

        return [$this->str($response, 'token'), (string) $this->refreshCookieValue($response)];
    }
}
