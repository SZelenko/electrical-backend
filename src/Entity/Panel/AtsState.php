<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Switch position of an ATS: source A, source B or off (shown as "0"). */
enum AtsState: string
{
    case A = 'A';
    case B = 'B';
    case Off = 'off';
}
