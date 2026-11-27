<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\AuthError;
use App\Tests\Factory\UserFactory;

final class MeTest extends AuthApiTestCase
{
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    public function testGetMeSuccess(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->api('GET', '/me', bearer: $this->bearerFor($user));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => $user->id->toRfc4122(),
                'email' => $user->email,
                'emailVerified' => true,
                'twoFactorEnabled' => false,
            ],
            $this->json($response),
        );
    }

    public function testGetMeWithoutTokenError(): void
    {
        $this->assertProblem($this->api('GET', '/me'), AuthError::TokenInvalid);
    }

    public function testGetMeGarbageTokenError(): void
    {
        $this->assertProblem($this->api('GET', '/me', bearer: 'garbage.token.value'), AuthError::TokenInvalid);
    }

    public function testEnableAndDisableTwoFactorSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $token = $this->bearerFor($user);

        $enabled = $this->api('PATCH', '/me/two-factor', ['enabled' => true, 'password' => UserFactory::PASSWORD], $token, headers: self::MERGE_PATCH);
        self::assertSame(200, $enabled->getStatusCode());
        self::assertTrue($this->json($enabled)['twoFactorEnabled']);
        self::assertTrue($this->reloadUser($user)->twoFactorEnabled);

        // The next login now asks for the second step.
        $login = $this->api('POST', '/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);
        self::assertSame(202, $login->getStatusCode());

        $disabled = $this->api('PATCH', '/me/two-factor', ['enabled' => false, 'password' => UserFactory::PASSWORD], $token, headers: self::MERGE_PATCH);
        self::assertSame(200, $disabled->getStatusCode());
        self::assertFalse($this->json($disabled)['twoFactorEnabled']);
        self::assertFalse($this->reloadUser($user)->twoFactorEnabled);
    }

    public function testTwoFactorWrongPasswordError(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->api('PATCH', '/me/two-factor', ['enabled' => true, 'password' => 'Wrong-Password-1!'], $this->bearerFor($user), headers: self::MERGE_PATCH);

        $this->assertProblem($response, AuthError::PasswordConfirmationFailed);
        self::assertFalse($this->reloadUser($user)->twoFactorEnabled);
    }

    public function testTwoFactorMissingFieldsError(): void
    {
        $user = $this->createVerifiedUser();
        $token = $this->bearerFor($user);

        $empty = $this->api('PATCH', '/me/two-factor', [], $token, headers: self::MERGE_PATCH);
        $noPassword = $this->api('PATCH', '/me/two-factor', ['enabled' => true], $token, headers: self::MERGE_PATCH);
        $noFlag = $this->api('PATCH', '/me/two-factor', ['password' => UserFactory::PASSWORD], $token, headers: self::MERGE_PATCH);

        self::assertSame(422, $empty->getStatusCode());
        self::assertSame(422, $noPassword->getStatusCode());
        self::assertContains('password', $this->violationPaths($noPassword));
        self::assertSame(422, $noFlag->getStatusCode());
        self::assertContains('enabled', $this->violationPaths($noFlag));
        self::assertFalse($this->reloadUser($user)->twoFactorEnabled);
    }

    public function testTwoFactorWithoutTokenError(): void
    {
        $response = $this->api('PATCH', '/me/two-factor', ['enabled' => true, 'password' => UserFactory::PASSWORD], headers: self::MERGE_PATCH);

        $this->assertProblem($response, AuthError::TokenInvalid);
    }

    public function testTwoFactorPasswordGuessesAreRateLimitedError(): void
    {
        $user = $this->createVerifiedUser();
        $token = $this->bearerFor($user);
        for ($i = 0; $i < 5; ++$i) {
            $this->api('PATCH', '/me/two-factor', ['enabled' => true, 'password' => 'Wrong-Password-1!'], $token, headers: self::MERGE_PATCH);
        }

        $response = $this->api('PATCH', '/me/two-factor', ['enabled' => true, 'password' => UserFactory::PASSWORD], $token, headers: self::MERGE_PATCH);

        $this->assertProblem($response, AuthError::RateLimited);
        self::assertArrayHasKey('retry-after', $response->getHeaders(false));
        self::assertFalse($this->reloadUser($user)->twoFactorEnabled);
    }
}
