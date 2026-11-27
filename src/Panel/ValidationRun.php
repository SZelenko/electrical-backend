<?php

declare(strict_types=1);

namespace App\Panel;

/** Per-validation mutable state of PanelContentValidator: collected violations and claimed ids. */
final class ValidationRun
{
    private const int MAX_VIOLATIONS = 200;
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var list<Violation> */
    private array $violations = [];

    /** @var array<string, true> */
    private array $claimedIds = [];

    public function add(string $path, string $message, ?string $id = null): void
    {
        if (\count($this->violations) < self::MAX_VIOLATIONS) {
            $this->violations[] = new Violation($path, $message, $id);
        }
    }

    /** Checks the id format and that no other item of the payload uses it. */
    public function claimId(string $id, string $path): void
    {
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            $this->add($path, 'Must be a lowercase RFC 4122 UUID.');

            return;
        }
        if (isset($this->claimedIds[$id])) {
            $this->add($path, 'Duplicate id within the payload.', $id);

            return;
        }
        $this->claimedIds[$id] = true;
    }

    public function intRange(int $value, int $min, int $max, string $path, ?string $id = null): bool
    {
        if ($value < $min || $value > $max) {
            $this->add($path, \sprintf('Must be between %d and %d.', $min, $max), $id);

            return false;
        }

        return true;
    }

    public function decimalRange(
        float $value,
        float $min,
        bool $minExclusive,
        float $max,
        int $scale,
        string $path,
        ?string $id = null,
    ): void {
        $rounded = Decimal::exact($value, $scale);
        if (null === $rounded) {
            $this->add($path, \sprintf('Must have at most %d decimal places.', $scale), $id);
        } elseif ($rounded < $min || ($minExclusive && $rounded <= $min) || $rounded > $max) {
            $this->add(
                $path,
                \sprintf('Must be %s %s and at most %s.', $minExclusive ? 'greater than' : 'at least', $min, $max),
                $id,
            );
        }
    }

    public function maxLength(string $value, int $max, string $path, ?string $id = null): void
    {
        if (mb_strlen($value) > $max) {
            $this->add($path, \sprintf('Must be at most %d characters long.', $max), $id);
        }
    }

    /** @return non-empty-list<Violation>|null */
    public function violations(): ?array
    {
        return [] === $this->violations ? null : $this->violations;
    }
}
