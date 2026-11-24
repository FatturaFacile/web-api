<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Enums;

/**
 * Supported country jurisdictions for Mod 074 Leave, Permits & Absences.
 */
enum LeaveCountry: string
{
    case CH = 'CH';
    case IT = 'IT';
}
