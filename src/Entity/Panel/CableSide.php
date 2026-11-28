<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** Drawing side of a cable kind (all input cables / all output cables); no electrical meaning. */
enum CableSide: string
{
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';
}
