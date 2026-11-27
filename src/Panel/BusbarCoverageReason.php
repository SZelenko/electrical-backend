<?php

declare(strict_types=1);

namespace App\Panel;

/** Why a busbar's covered set is (in)valid; see {@see BusbarCoverage}. */
enum BusbarCoverageReason
{
    case None;
    case AnchorMissing;
    case TooFew;
    case ForeignItem;
    case Gap;
    case MixedSides;
}
