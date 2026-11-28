<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel\Cable;
use App\Entity\Panel\CableWire;
use App\Entity\Panel\Panel;
use App\Entity\Panel\WireColour;
use App\Entity\User;
use App\Panel\Content\PanelContent;
use App\Repository\PanelRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Application service of the panel use cases (transaction script per method; one flush each).
 * Every mutating use case loads the panel owner-scoped with a row lock (`FOR UPDATE`), so
 * concurrent writers are serialised and the explicit version check cannot race. A foreign or
 * missing panel is 404 before any other check.
 */
final readonly class PanelService
{
    private const string NAME_KEY_INDEX = 'uniq_panel__owner_name_key';

    public function __construct(
        private EntityManagerInterface $em,
        private PanelRepository $panels,
        private PanelNameNormalizer $names,
        private PanelContentValidator $validator,
        private PanelContentMapper $mapper,
        private ClockInterface $clock,
    ) {}

    public function create(User $owner, string $rawName): PanelDetails
    {
        $name = $this->names->normalize($rawName);

        return $this->transactional(function () use ($owner, $name): PanelDetails {
            if ($this->panels->isNameKeyTaken($owner, $name->key)) {
                throw new PanelException(PanelError::NameConflict);
            }

            $panel = new Panel($owner, $name, $this->now());
            $this->em->persist($panel);

            $cable = Cable::input(Uuid::v7(), $panel, 0, '', 230.0, 50.0, 4.0);
            $this->em->persist($cable);
            foreach ([WireColour::Brown, WireColour::Blue] as $position => $colour) {
                $this->em->persist(new CableWire(Uuid::v7(), $cable, $position, $colour));
            }
            $this->em->flush();

            return $this->details($panel);
        });
    }

    public function get(User $owner, Uuid $id): PanelDetails
    {
        return $this->details($this->panels->findOne($owner, $id) ?? throw $this->notFound());
    }

    public function rename(User $owner, Uuid $id, string $rawName): PanelDetails
    {
        $name = $this->names->normalize($rawName);

        $lockedVersion = null;

        return $this->transactional(function () use ($owner, $id, $name, &$lockedVersion): PanelDetails {
            $panel = $this->panels->findOne($owner, $id, PanelState::Any, true) ?? throw $this->notFound();
            $lockedVersion = $panel->version;
            if (!$panel->isTrashed && $this->panels->isNameKeyTaken($owner, $name->key, $panel->id)) {
                throw new PanelException(PanelError::NameConflict, $lockedVersion);
            }

            $panel->rename($name, $this->now());
            $this->em->flush();

            return $this->details($panel);
        }, lockedVersion: $lockedVersion);
    }

    public function saveContent(User $owner, Uuid $id, int $version, PanelContent $content): PanelDetails
    {
        // Validated before the transaction opens, so no row lock is held for it (a stale and invalid payload is a 422).
        $this->validator->validate($content);
        $lockedVersion = null;

        return $this->transactional(function () use ($owner, $id, $version, $content, &$lockedVersion): PanelDetails {
            $panel = $this->panels->findOne($owner, $id, PanelState::Active, true) ?? throw $this->notFound();
            $lockedVersion = $panel->version;
            if ($panel->version !== $version) {
                throw new PanelException(PanelError::VersionConflict, $panel->version);
            }

            $this->panels->deleteContent($panel);
            $tree = $this->mapper->build($panel, $content);
            foreach ($tree->all() as $row) {
                $this->em->persist($row);
            }
            $panel->contentReplaced($this->now(), $content->railWidthModules, $content->inputCableSide, $content->outputCableSide);
            $this->em->flush();

            return new PanelDetails($panel, $this->mapper->toContent($tree, $panel));
        }, true, $lockedVersion);
    }

    public function moveToTrash(User $owner, Uuid $id): void
    {
        $this->transactional(function () use ($owner, $id): void {
            $panel = $this->panels->findOne($owner, $id, PanelState::Active, true) ?? throw $this->notFound();
            $panel->moveToTrash($this->now());
            $this->em->flush();
        });
    }

    public function restore(User $owner, Uuid $id): PanelDetails
    {
        $lockedVersion = null;

        return $this->transactional(function () use ($owner, $id, &$lockedVersion): PanelDetails {
            $panel = $this->panels->findOne($owner, $id, PanelState::Any, true) ?? throw $this->notFound();
            $lockedVersion = $panel->version;
            if (!$panel->isTrashed) {
                throw new PanelException(PanelError::NotInTrash, $lockedVersion);
            }
            if ($this->panels->isNameKeyTaken($owner, $panel->nameKey)) {
                throw new PanelException(PanelError::NameConflict, $lockedVersion);
            }

            $panel->restore();
            $this->em->flush();

            return $this->details($panel);
        }, lockedVersion: $lockedVersion);
    }

    public function deletePermanently(User $owner, Uuid $id): void
    {
        $this->transactional(function () use ($owner, $id): void {
            $panel = $this->panels->findOne($owner, $id, PanelState::Any, true) ?? throw $this->notFound();
            if (!$panel->isTrashed) {
                throw new PanelException(PanelError::NotInTrash, $panel->version);
            }

            $this->panels->deletePermanently($panel);
        });
    }

    private function details(Panel $panel): PanelDetails
    {
        return new PanelDetails($panel, $this->mapper->toContent($this->panels->loadTree($panel), $panel));
    }

    /**
     * Runs the callback in a transaction (one flush at its end) and maps the unique violations we
     * expect to catalogue problems: the name-key index always; a child primary key (a client id
     * already used by another panel) only when `$contentWrite` is set. Anything else is rethrown.
     * `$lockedVersion` is filled by the callback once it holds the lock; it is attached to those 409s.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function transactional(callable $callback, bool $contentWrite = false, ?int &$lockedVersion = null): mixed
    {
        try {
            return $this->em->wrapInTransaction($callback);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), self::NAME_KEY_INDEX)) {
                throw new PanelException(PanelError::NameConflict, $lockedVersion, $e);
            }
            if ($contentWrite && 1 === preg_match('/unique constraint "[a-z_]+_pkey"/', $e->getMessage())) {
                throw new PanelException(PanelError::ContentIdConflict, $lockedVersion, $e);
            }

            throw $e;
        }
    }

    /** Current time without sub-second precision (the columns are timestamptz(0)). */
    private function now(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTimestamp($now->getTimestamp());
    }

    private function notFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('Panel not found.');
    }
}
