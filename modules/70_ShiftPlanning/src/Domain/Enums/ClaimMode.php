<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Enums;

enum ClaimMode: string
{
    /** Take an open shift of a published roster. */
    case Claim = 'claim';

    /** Offer an own shift to a colleague (or to every qualified colleague). */
    case Swap = 'swap';
}
