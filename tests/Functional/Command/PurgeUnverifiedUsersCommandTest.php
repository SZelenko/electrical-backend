<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\OneTimeCode;
use App\Entity\OneTimeCodePurpose;
use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class PurgeUnverifiedUsersCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private KernelInterface $bootedKernel;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime();
        $this->bootedKernel = self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::mockTime(false);
        parent::tearDown();
    }

    /** @param array<string, string> $input */
    private function runPurge(array $input = []): CommandTester
    {
        $tester = new CommandTester(new Application($this->bootedKernel)->find('app:auth:purge-unverified'));
        $tester->execute($input);

        return $tester;
    }

    private function createUser(int $ageHours, bool $verified): User
    {
        $factory = UserFactory::new(['now' => Clock::get()->now()->modify(sprintf('-%d hours', $ageHours))]);
        if ($verified) {
            $factory = $factory->verified();
        }
        $user = $factory->create();

        $em = $this->em();
        $em->persist(new OneTimeCode($user, OneTimeCodePurpose::EmailVerification, str_repeat('a', 64), null, Clock::get()->now()));
        $em->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    private function exists(User $user): bool
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = ?', [$user->id->toRfc4122()]);
        self::assertIsNumeric($count);

        return 1 === (int) $count;
    }

    private function codeCount(): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM one_time_code');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    public function testPurgeDeletesOldUnverifiedUsersSuccess(): void
    {
        $old = $this->createUser(25, verified: false);
        $young = $this->createUser(23, verified: false);
        $oldVerified = $this->createUser(100, verified: true);

        $tester = $this->runPurge();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 unverified account(s) deleted', $tester->getDisplay());
        self::assertFalse($this->exists($old));
        self::assertTrue($this->exists($young));
        self::assertTrue($this->exists($oldVerified));
        self::assertSame(2, $this->codeCount(), 'The codes of the deleted user are removed with it.');
    }

    public function testPurgeIsIdempotentSuccess(): void
    {
        $this->createUser(30, verified: false);
        $this->runPurge();

        $second = $this->runPurge();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('0 unverified account(s) deleted', $second->getDisplay());
    }

    public function testPurgeOlderThanHoursOptionSuccess(): void
    {
        $thirtyHours = $this->createUser(30, verified: false);
        $seventyHours = $this->createUser(70, verified: false);

        $tester = $this->runPurge(['--older-than-hours' => '48']);

        self::assertStringContainsString('1 unverified account(s) deleted', $tester->getDisplay());
        self::assertTrue($this->exists($thirtyHours));
        self::assertFalse($this->exists($seventyHours));
    }

    public function testPurgeUsesTheClockSuccess(): void
    {
        $user = $this->createUser(2, verified: false);
        $this->runPurge();
        self::assertTrue($this->exists($user));

        Clock::get()->sleep(23 * 3600);
        $this->runPurge();

        self::assertFalse($this->exists($user));
    }

    public function testPurgeInvalidOptionError(): void
    {
        $user = $this->createUser(48, verified: false);

        $tester = $this->runPurge(['--older-than-hours' => '0']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertTrue($this->exists($user));
    }
}
