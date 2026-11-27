<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Panel\Cable;
use App\Entity\Panel\CableWire;
use App\Entity\Panel\DinRail;
use App\Entity\Panel\Element;
use App\Entity\Panel\ElementPole;
use App\Entity\Panel\Panel;
use App\Entity\Panel\PanelWire;
use App\Entity\Panel\PanelWireBend;
use App\Entity\Panel\TerminalBlock;
use App\Entity\User;
use App\Panel\PanelState;
use App\Panel\PanelSummary;
use App\Panel\PanelTree;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Repository of the Panel aggregate. Every method takes the owner: there is deliberately no
 * unscoped lookup, so a foreign panel is indistinguishable from a missing one.
 *
 * @extends ServiceEntityRepository<Panel>
 */
class PanelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Panel::class);
    }

    /** With `$forUpdate` a transaction must be active (SELECT ... FOR UPDATE). */
    public function findOne(User $owner, Uuid $id, PanelState $state = PanelState::Any, bool $forUpdate = false): ?Panel
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->andWhere('p.owner = :owner')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('owner', $owner);

        match ($state) {
            PanelState::Active => $qb->andWhere('p.deletedAt IS NULL'),
            PanelState::Any => $qb,
        };

        $query = $qb->getQuery();
        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        /** @var Panel|null */
        return $query->getOneOrNullResult();
    }

    /** True when an ACTIVE panel of the owner already has this name key (optionally ignoring one panel). */
    public function isNameKeyTaken(User $owner, string $nameKey, ?Uuid $excluding = null): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.owner = :owner')
            ->andWhere('p.nameKey = :key')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('key', $nameKey);
        if (null !== $excluding) {
            $qb->andWhere('p.id <> :excluding')->setParameter('excluding', $excluding, UuidType::NAME);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return list<PanelSummary> active panels by updatedAt desc, or trashed ones by deletedAt desc */
    public function findSummaries(User $owner, bool $trashed, int $offset, int $limit): array
    {
        $dql = \sprintf(
            'SELECT NEW %s(p.id, p.name, p.version, p.createdAt, p.updatedAt, p.deletedAt) FROM %s p '
            . 'WHERE p.owner = :owner AND p.deletedAt %s ORDER BY %s DESC, p.id DESC',
            PanelSummary::class,
            Panel::class,
            $trashed ? 'IS NOT NULL' : 'IS NULL',
            $trashed ? 'p.deletedAt' : 'p.updatedAt',
        );

        /** @var list<PanelSummary> */
        return $this->getEntityManager()->createQuery($dql)
            ->setParameter('owner', $owner)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getResult();
    }

    public function countSummaries(User $owner, bool $trashed): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.owner = :owner')
            ->andWhere($trashed ? 'p.deletedAt IS NOT NULL' : 'p.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Loads all child rows of the panel; parents are loaded before the rows that reference them. */
    public function loadTree(Panel $panel): PanelTree
    {
        $em = $this->getEntityManager();
        $select = static function (string $dql) use ($em, $panel): array {
            /** @var list<object> $rows */
            $rows = $em->createQuery($dql)->setParameter('panel', $panel)->getResult();

            return $rows;
        };

        /** @var list<Cable> $cables */
        $cables = $select(\sprintf('SELECT c FROM %s c WHERE c.panel = :panel', Cable::class));
        /** @var list<CableWire> $cableWires */
        $cableWires = $select(\sprintf('SELECT w FROM %s w JOIN w.cable c WHERE c.panel = :panel', CableWire::class));
        /** @var list<DinRail> $rails */
        $rails = $select(\sprintf('SELECT r FROM %s r WHERE r.panel = :panel', DinRail::class));
        /** @var list<Element> $elements */
        $elements = $select(\sprintf('SELECT e FROM %s e JOIN e.rail r WHERE r.panel = :panel', Element::class));
        /** @var list<ElementPole> $poles */
        $poles = $select(\sprintf('SELECT p FROM %s p JOIN p.element e JOIN e.rail r WHERE r.panel = :panel', ElementPole::class));
        /** @var list<TerminalBlock> $blocks */
        $blocks = $select(\sprintf('SELECT b FROM %s b WHERE b.panel = :panel', TerminalBlock::class));
        /** @var list<PanelWire> $wires */
        $wires = $select(\sprintf('SELECT w FROM %s w WHERE w.panel = :panel', PanelWire::class));
        /** @var list<PanelWireBend> $bends */
        $bends = $select(\sprintf('SELECT b FROM %s b JOIN b.wire w WHERE w.panel = :panel', PanelWireBend::class));

        return new PanelTree($cables, $cableWires, $rails, $elements, $poles, $blocks, $wires, $bends);
    }

    /**
     * Bulk-deletes the content of the panel (wires, terminal blocks, rails, cables); the database cascades to the
     * rest. Bypasses the unit of work, so every child row it still tracks (from an earlier read or
     * save in the same entity manager) is detached afterwards, which lets the caller persist new
     * rows that reuse the same ids.
     */
    public function deleteContent(Panel $panel): void
    {
        $em = $this->getEntityManager();
        foreach ([PanelWire::class, TerminalBlock::class, DinRail::class, Cable::class] as $class) {
            $em->createQuery(\sprintf('DELETE FROM %s x WHERE x.panel = :panel', $class))
                ->setParameter('panel', $panel)
                ->execute();
        }

        $uow = $em->getUnitOfWork();
        $identityMap = $uow->getIdentityMap();
        foreach (
            [
                PanelWireBend::class,
                PanelWire::class,
                ElementPole::class,
                Element::class,
                TerminalBlock::class,
                DinRail::class,
                CableWire::class,
                Cable::class,
            ] as $class
        ) {
            foreach ($identityMap[$class] ?? [] as $entity) {
                $em->detach($entity);
            }
        }
    }

    /** Hard-deletes the panel row; the database cascades to all content. */
    public function deletePermanently(Panel $panel): void
    {
        $em = $this->getEntityManager();
        $em->createQuery(\sprintf('DELETE FROM %s p WHERE p.id = :id', Panel::class))
            ->setParameter('id', $panel->id, UuidType::NAME)
            ->execute();
        $em->detach($panel);
    }
}
