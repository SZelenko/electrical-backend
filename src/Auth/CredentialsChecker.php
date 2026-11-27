<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Password verification. For unknown emails a verification against a precomputed dummy hash is performed,
 * so known and unknown emails cost the same at request time. The dummy hash is created once with the
 * configured hasher and kept in the cache; when the hasher's algorithm/cost changes (needsRehash) it is regenerated.
 */
final readonly class CredentialsChecker
{
    private const string DUMMY_HASH_KEY = 'auth_dummy_password_hash';

    public function __construct(
        private UserRepository $users,
        private PasswordHasherFactoryInterface $hasherFactory,
        private CacheInterface $cache,
    ) {}

    public function authenticate(string $email, #[\SensitiveParameter] string $password): ?User
    {
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $hasher = $this->hasherFactory->getPasswordHasher(User::class);
            $hasher->verify($this->dummyHash(), $password);

            return null;
        }

        return $this->isPasswordValid($user, $password) ? $user : null;
    }

    public function isPasswordValid(User $user, #[\SensitiveParameter] string $password): bool
    {
        return $this->hasherFactory->getPasswordHasher($user)->verify($user->getPassword(), $password);
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return $this->hasherFactory->getPasswordHasher(User::class)->hash($password);
    }

    private function dummyHash(): string
    {
        $hasher = $this->hasherFactory->getPasswordHasher(User::class);
        $generate = static fn(): string => $hasher->hash(bin2hex(random_bytes(16)));

        $hash = $this->cache->get(self::DUMMY_HASH_KEY, $generate);
        if ($hasher->needsRehash($hash)) {
            $this->cache->delete(self::DUMMY_HASH_KEY);
            $hash = $this->cache->get(self::DUMMY_HASH_KEY, $generate);
        }

        return $hash;
    }
}
