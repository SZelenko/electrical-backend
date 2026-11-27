<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<User> */
final class UserFactory extends PersistentObjectFactory
{
    /** Plain password of every factory-made user (meets the registration strength rule). */
    public const string PASSWORD = 'Zq7!vLm29#xKpw-Rail';

    public static function class(): string
    {
        return User::class;
    }

    public function verified(): static
    {
        return $this->afterInstantiate(
            static function (User $user): void {
                $user->markEmailVerified(Clock::get()->now());
            },
        );
    }

    public function withTwoFactor(): static
    {
        return $this->afterInstantiate(
            static function (User $user): void {
                $user->enableTwoFactor();
            },
        );
    }

    protected function defaults(): array
    {
        static $hash = null;
        $hash ??= password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

        return [
            'email' => 'user.' . bin2hex(random_bytes(6)) . '@example.test',
            'passwordHash' => $hash,
            'now' => Clock::get()->now(),
        ];
    }
}
