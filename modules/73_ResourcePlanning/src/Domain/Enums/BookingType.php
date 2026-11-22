<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Enums;

/**
 * A tentative booking holds capacity for an open CRM opportunity (Mod 035); a committed one belongs to an active project
 * (Mod 078).
 */
enum BookingType: string
{
    case SoftTentative = 'SoftTentative';
    case HardCommitted = 'HardCommitted';

    public function isCommitted(): bool
    {
        return $this === self::HardCommitted;
    }
}
