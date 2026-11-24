<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Review lifecycle of a detected or manually entered Bezugsteuer entry.
 */
enum BezugsteuerStatus: string
{
    case Detected = 'detected';
    case Confirmed = 'confirmed';
    case Excluded = 'excluded';
    case Declared = 'declared';
}
