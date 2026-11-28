<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** One of the two terminal rows of a panel (row blocks are not on a DIN rail). */
enum TerminalRow: string
{
    case Top = 'top';
    case Bottom = 'bottom';
}
