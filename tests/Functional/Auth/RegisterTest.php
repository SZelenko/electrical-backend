<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use App\Entity\OneTimeCodePurpose;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

final class RegisterTest extends AuthApiTestCase
{
    private const string STRONG = 'Hy9#mQ2$vLx8-Panel';

    public function testRegisterNewAccountSuccess(): void
    {
        $response = $this->api('POST', '/auth/register', ['email' => 'New.User@Example.com', 'password' => self::STRONG]);

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame('new.user@example.com', $data['email']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $this->str($response, 'id'));
        self::assertArrayNotHasKey('password', $data);

        $user = $this->findUserByEmail('new.user@example.com');
        self::assertNotNull($user);
        self::assertFalse($user->isEmailVerified);
        self::assertNotSame(self::STRONG, $user->password);
        self::assertSame($this->str($response, 'id'), $user->id->toRfc4122());

        self::assertSame(1, $this->sentEmailCount());
        self::assertSame('new.user@example.com', $this->lastEmailRecipient());
        $row = $this->codeRow($user, OneTimeCodePurpose::EmailVerification);
        self::assertNotNull($row);
        self::assertSame(0, $row->attempts);
    }

    public function testRegisterUnverifiedExistingEmailIsNotOverwrittenError(): void
    {
        $existing = $this->createUnverifiedUser();

        $response = $this->api('POST', '/auth/register', ['email' => $existing->email, 'password' => self::STRONG]);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('email', $this->violationPaths($response));
        self::assertSame(0, $this->sentEmailCount());

        // The original password still works (it reaches the "not verified" step); the new one does not.
        $original = $this->api('POST', '/auth/login', ['email' => $existing->email, 'password' => UserFactory::PASSWORD]);
        $this->assertProblem($original, AuthError::EmailNotVerified);
        $attempted = $this->api('POST', '/auth/login', ['email' => $existing->email, 'password' => self::STRONG]);
        $this->assertProblem($attempted, AuthError::InvalidCredentials);
    }

    public function testRegisterSameEmailTwiceError(): void
    {
        $first = $this->api('POST', '/auth/register', ['email' => 'twice@example.com', 'password' => self::STRONG]);
        self::assertSame(201, $first->getStatusCode());

        $second = $this->api('POST', '/auth/register', ['email' => 'Twice@Example.com', 'password' => 'Kt5%wNp3!zRb-Switch']);

        self::assertSame(422, $second->getStatusCode());
        self::assertContains('email', $this->violationPaths($second));
        self::assertSame(0, $this->sentEmailCount());
        $login = $this->api('POST', '/auth/login', ['email' => 'twice@example.com', 'password' => self::STRONG]);
        $this->assertProblem($login, AuthError::EmailNotVerified);
    }

    public function testRegisterVerifiedEmailError(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->api('POST', '/auth/register', ['email' => strtoupper($user->email), 'password' => self::STRONG]);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('email', $this->violationPaths($response));
        self::assertSame(0, $this->sentEmailCount());
        // The verified account is untouched.
        $ok = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testRegisterWeakPasswordError(): void
    {
        $response = $this->api('POST', '/auth/register', ['email' => 'weak@example.com', 'password' => 'aaaaaaaa']);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('password', $this->violationPaths($response));
        self::assertNull($this->findUserByEmail('weak@example.com'));
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testRegisterTooShortPasswordError(): void
    {
        $response = $this->api('POST', '/auth/register', ['email' => 'short@example.com', 'password' => 'Ab1!xy']);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('password', $this->violationPaths($response));
    }

    /** @return iterable<string, array{string}> */
    public static function badEmails(): iterable
    {
        yield 'no at sign' => ['not-an-email'];
        yield 'blank' => [''];
        yield 'non-ascii' => ['üser@example.com'];
        yield 'too long' => [str_repeat('a', 250) . '@example.com'];
    }

    #[DataProvider('badEmails')]
    public function testRegisterInvalidEmailError(string $email): void
    {
        $response = $this->api('POST', '/auth/register', ['email' => $email, 'password' => self::STRONG]);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('email', $this->violationPaths($response));
        self::assertSame(0, $this->sentEmailCount());
    }

    public function testRegisterMissingFieldsError(): void
    {
        $response = $this->api('POST', '/auth/register', []);

        self::assertSame(422, $response->getStatusCode());
    }
}
