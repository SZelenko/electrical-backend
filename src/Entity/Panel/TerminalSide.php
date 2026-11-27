<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Logical side of an element pole terminal (drawing may swap the sides, the logic does not). */
enum TerminalSide: string
{
    case Input = 'input';
    case Output = 'output';
}
