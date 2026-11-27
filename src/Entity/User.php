<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user__email', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\Column(length: 254)]
    public private(set) string $email;

    #[ORM\Column(length: 255)]
    public private(set) string $password;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(options: ['default' => false])]
    public private(set) bool $twoFactorEnabled = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    public bool $isEmailVerified {
        get => null !== $this->emailVerifiedAt;
    }

    public function __construct(string $email, string $passwordHash, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->email = self::normalizeEmail($email);
        $this->password = $passwordHash;
        $this->createdAt = $now;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    #[\Deprecated]
    public function eraseCredentials(): void {}

    public function changePasswordHash(string $passwordHash): void
    {
        $this->password = $passwordHash;
    }

    public function markEmailVerified(\DateTimeImmutable $now): void
    {
        $this->emailVerifiedAt ??= $now;
    }

    public function enableTwoFactor(): void
    {
        $this->twoFactorEnabled = true;
    }

    public function disableTwoFactor(): void
    {
        $this->twoFactorEnabled = false;
    }
}
