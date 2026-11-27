<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Standard cross-section series of a panel wire, in mm²; the string value is the wire format. */
enum WireCrossSection: string
{
    case Mm1 = '1';
    case Mm1_5 = '1.5';
    case Mm2_5 = '2.5';
    case Mm4 = '4';
    case Mm6 = '6';
}
