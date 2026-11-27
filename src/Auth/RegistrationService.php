<?php

declare(strict_types=1);

namespace App\Auth;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final readonly class RegistrationService
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private CredentialsChecker $credentials,
        private OneTimeCodeService $codes,
        private ClockInterface $clock,
    ) {}

    /**
     * Creates the account and sends the verification code. An existing email (verified or not) is rejected:
     * overwriting an unverified account would allow takeover before verification.
     *
     * Concurrent registrations of one email are serialised by a transaction-scoped PostgreSQL advisory lock,
     * so the loser sees the winner's row and gets the same 422 (no unique-violation race). The exception is thrown
     * after the transaction ends, because throwing inside wrapInTransaction would close the EntityManager.
     *
     * @throws ValidationException when the email is already registered
     */
    public function register(string $email, #[\SensitiveParameter] string $password): User
    {
        $hash = $this->credentials->hash($password);
        $normalized = User::normalizeEmail($email);

        $user = $this->em->wrapInTransaction(function () use ($normalized, $hash): ?User {
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['register:' . $normalized]);

            if (null !== $this->users->findOneByEmail($normalized)) {
                return null;
            }

            $user = new User($normalized, $hash, $this->clock->now());
            $this->em->persist($user);

            return $user;
        });

        // Thrown outside the transaction: an exception inside wrapInTransaction closes the EntityManager.
        if (null === $user) {
            throw $this->emailTaken($normalized);
        }

        $this->codes->sendEmailVerification($user);

        return $user;
    }

    private function emailTaken(string $email): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation('This email is already registered.', null, [], null, 'email', $email),
        ]));
    }
}
