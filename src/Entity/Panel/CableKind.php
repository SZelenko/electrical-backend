<?php

declare(strict_types=1);

namespace App\Entity\Panel;

enum CableKind: string
{
    case Input = 'input';
    case Output = 'output';
}
