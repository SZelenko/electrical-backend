<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Kind of an element, fixed at creation; the string value is the wire format and the column value. */
enum ElementKind: string
{
    case Device = 'device';
    case Ats = 'ats';
}
