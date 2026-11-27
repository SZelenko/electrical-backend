<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:auth:purge-unverified', description: 'Deletes accounts whose email was not verified within the given number of hours.')]
final readonly class PurgeUnverifiedUsersCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Minimum account age in hours', name: 'older-than-hours')]
        int $olderThanHours = 24,
    ): int {
        if ($olderThanHours < 1) {
            $io->error('--older-than-hours must be at least 1.');

            return Command::INVALID;
        }

        $threshold = $this->clock->now()->modify(sprintf('-%d hours', $olderThanHours));

        // One-time codes are removed by ON DELETE CASCADE; refresh tokens cannot exist for unverified users.
        /** @var int $count */
        $count = $this->em->createQuery('DELETE FROM ' . User::class . ' u WHERE u.emailVerifiedAt IS NULL AND u.createdAt < :threshold')
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->execute();

        $io->success(sprintf('%d unverified account(s) deleted.', $count));

        return Command::SUCCESS;
    }
}
