<?php

declare(strict_types=1);

namespace App\Panel;

/** Which panels an owner-scoped lookup may see. */
enum PanelState
{
    case Active;
    case Any;
}
