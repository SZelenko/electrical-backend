<?php

declare(strict_types=1);

namespace App\Entity\Panel;

/** The only allowed wire colours (cable wires and panel wires). */
enum WireColour: string
{
    case Black = 'black';
    case Brown = 'brown';
    case Red = 'red';
    case Blue = 'blue';
    case GreenYellow = 'green-yellow';
}
