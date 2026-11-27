<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use App\Entity\User;
use App\Panel\PanelName;
use App\Repository\PanelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Aggregate root of a panel. Children (cables, rails, elements, blocks, wires) reference the panel
 * from their side only: the content is replaced as a whole by bulk delete + insert, so the root
 * never holds child collections. `version` is bumped explicitly by every mutating method, which
 * is correct for any number of changes within the same second (callers hold a row lock).
 */
#[ORM\Entity(repositoryClass: PanelRepository::class)]
#[ORM\Table(name: 'panel')]
#[ORM\UniqueConstraint(
    name: 'uniq_panel__owner_name_key',
    fields: ['owner', 'nameKey'],
    options: ['where' => '(deleted_at IS NULL)'],
)]
#[ORM\Index(name: 'idx_panel__owner_deleted_updated', fields: ['owner', 'deletedAt', 'updatedAt'])]
class Panel
{
    public const int DEFAULT_RAIL_WIDTH_MODULES = 60;
    public const int MIN_RAIL_WIDTH_MODULES = 1;
    public const int MAX_RAIL_WIDTH_MODULES = 60;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) User $owner;

    #[ORM\Column(length: 100)]
    public private(set) string $name;

    /** Case-folded, NFC-normalised name; maintained only here (see PanelName). */
    #[ORM\Column(length: 255)]
    public private(set) string $nameKey;

    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $version = 1;

    /** Width of every DIN rail of the panel (and of the terminal row together with the input cables). */
    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $railWidthModules = self::DEFAULT_RAIL_WIDTH_MODULES;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $deletedAt = null;

    public bool $isTrashed {
        get => null !== $this->deletedAt;
    }

    public function __construct(User $owner, PanelName $name, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = $name->value;
        $this->nameKey = $name->key;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function rename(PanelName $name, \DateTimeImmutable $now): void
    {
        $this->name = $name->value;
        $this->nameKey = $name->key;
        $this->updatedAt = $now;
        ++$this->version;
    }

    public function contentReplaced(\DateTimeImmutable $now, int $railWidthModules): void
    {
        if ($railWidthModules < self::MIN_RAIL_WIDTH_MODULES || $railWidthModules > self::MAX_RAIL_WIDTH_MODULES) {
            throw new \InvalidArgumentException('The rail width must be between 1 and 60 modules.');
        }
        $this->railWidthModules = $railWidthModules;
        $this->updatedAt = $now;
        ++$this->version;
    }

    public function moveToTrash(\DateTimeImmutable $now): void
    {
        $this->deletedAt = $now;
        ++$this->version;
    }

    public function restore(): void
    {
        $this->deletedAt = null;
        ++$this->version;
    }
}
