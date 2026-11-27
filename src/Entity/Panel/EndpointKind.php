<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Discriminator of a panel wire endpoint; the string value is the wire format. */
enum EndpointKind: string
{
    case Pole = 'pole';
    case Terminal = 'terminal';
    case CableWire = 'cableWire';
    case Busbar = 'busbar';
}
