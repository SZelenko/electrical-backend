<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/**
 * Drawing-only side at which a wire is attached to a terminal-block contact: left/right on a rail
 * block, top/bottom on a terminal-row block. Electrically it is the same contact either way.
 */
enum ContactSide: string
{
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';
}
