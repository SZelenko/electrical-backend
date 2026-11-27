<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Busbar type; the string value is the wire format and the stored value. */
enum BusbarType: string
{
    case L = 'L';
    case LN = 'LN';

    /** Number of feeds (wire contacts) of the busbar: L = 1, LN = 2 (phase, neutral). */
    public function feedCount(): int
    {
        return match ($this) {
            self::L => 1,
            self::LN => 2,
        };
    }
}
