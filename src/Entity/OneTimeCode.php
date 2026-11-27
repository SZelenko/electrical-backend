<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OneTimeCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One row per (user, purpose), overwritten in place on every reissue.
 */
#[ORM\Entity(repositoryClass: OneTimeCodeRepository::class)]
#[ORM\Table(name: 'one_time_code')]
#[ORM\UniqueConstraint(name: 'uniq_one_time_code__user_purpose', fields: ['user', 'purpose'])]
#[ORM\UniqueConstraint(name: 'uniq_one_time_code__login_token_hash', fields: ['loginTokenHash'])]
class OneTimeCode
{
    public const int TTL_SECONDS = 600;
    public const int MAX_ATTEMPTS = 5;
    public const int COOLDOWN_SECONDS = 60;
    public const int LOGIN_TOKEN_WINDOW_SECONDS = 300;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) User $user;

    #[ORM\Column(length: 32, enumType: OneTimeCodePurpose::class)]
    public private(set) OneTimeCodePurpose $purpose;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    public private(set) string $codeHash;

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    public private(set) ?string $loginTokenHash = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public private(set) int $attempts = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $consumedAt = null;

    public function __construct(
        User $user,
        OneTimeCodePurpose $purpose,
        string $codeHash,
        ?string $loginTokenHash,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->purpose = $purpose;
        $this->reissue($codeHash, $loginTokenHash, $now);
    }

    public function canReissue(\DateTimeImmutable $now): bool
    {
        return $this->issuedAt <= $now->modify('-' . self::COOLDOWN_SECONDS . ' seconds');
    }

    public function reissue(string $codeHash, ?string $loginTokenHash, \DateTimeImmutable $now): void
    {
        $this->codeHash = $codeHash;
        $this->loginTokenHash = $loginTokenHash;
        $this->attempts = 0;
        $this->consumedAt = null;
        $this->issuedAt = $now;
        $this->expiresAt = $now->modify('+' . self::TTL_SECONDS . ' seconds');
    }

    public function rotateLoginToken(string $loginTokenHash): void
    {
        $this->loginTokenHash = $loginTokenHash;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt
            && $this->expiresAt > $now
            && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function isLoginWindowOpen(\DateTimeImmutable $now): bool
    {
        return $this->issuedAt->modify('+' . self::LOGIN_TOKEN_WINDOW_SECONDS . ' seconds') > $now;
    }

    public function registerAttempt(): void
    {
        ++$this->attempts;
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
    }
}
