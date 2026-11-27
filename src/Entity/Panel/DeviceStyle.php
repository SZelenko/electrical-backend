<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/**
 * Visual style of a device (no effect on the short-circuit check); the string value is the wire format.
 * Adding a case requires a migration that updates the chk_element__style CHECK constraint.
 */
enum DeviceStyle: string
{
    case Breaker = 'breaker';
    case Rcd = 'rcd';
    case VoltageRelay = 'voltageRelay';
}
