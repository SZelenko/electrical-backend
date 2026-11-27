<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OneTimeCode;
use App\Entity\OneTimeCodePurpose;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OneTimeCode> */
class OneTimeCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OneTimeCode::class);
    }

    /** Must be called inside a transaction: the row stays locked until it ends. */
    public function findOneByUserAndPurposeForUpdate(User $user, OneTimeCodePurpose $purpose): ?OneTimeCode
    {
        /** @var OneTimeCode|null $row */
        $row = $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.purpose = :purpose')
            ->setParameter('user', $user->id, 'uuid')
            ->setParameter('purpose', $purpose)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row;
    }

    /** Must be called inside a transaction: the row stays locked until it ends. */
    public function findOneByLoginTokenHashForUpdate(string $loginTokenHash): ?OneTimeCode
    {
        /** @var OneTimeCode|null $row */
        $row = $this->createQueryBuilder('c')
            ->andWhere('c.loginTokenHash = :hash')
            ->andWhere('c.purpose = :purpose')
            ->setParameter('hash', $loginTokenHash)
            ->setParameter('purpose', OneTimeCodePurpose::Login2fa)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row;
    }

    /** Scalar lookup (nothing hydrated, nothing locked): the owner of a pending 2FA token, used as rate-limit key. */
    public function findUserIdByLoginTokenHash(string $loginTokenHash): ?string
    {
        $id = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.user)')
            ->andWhere('c.loginTokenHash = :hash')
            ->andWhere('c.purpose = :purpose')
            ->setParameter('hash', $loginTokenHash)
            ->setParameter('purpose', OneTimeCodePurpose::Login2fa)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return \is_string($id) ? $id : null;
    }

    public function hasUsableCode(User $user, OneTimeCodePurpose $purpose, \DateTimeImmutable $now): bool
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.user = :user')
            ->andWhere('c.purpose = :purpose')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.expiresAt > :now')
            ->andWhere('c.attempts < :max')
            ->setParameter('user', $user->id, 'uuid')
            ->setParameter('purpose', $purpose)
            ->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('max', OneTimeCode::MAX_ATTEMPTS)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
