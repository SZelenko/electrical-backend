<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Visual style of a device (no effect on the short-circuit check); the string value is the wire format. */
enum DeviceStyle: string
{
    case Breaker = 'breaker';
    case Rcd = 'rcd';
    case VoltageRelay = 'voltageRelay';
}
