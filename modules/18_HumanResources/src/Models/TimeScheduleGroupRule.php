<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Validation\ValidationException;

/**
 * A version of the rules of a group, in force from valid_from until the next version. A version is never changed: a new
 * one is added, so the rules behind a past payroll view stay readable.
 */
final class TimeScheduleGroupRule extends BaseEnterpriseModel
{
    protected $table = 'time_schedule_group_rules';

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw ValidationException::withMessages(['valid_from' => __('hr_errors.time_schedule_group_rule.version_immutable')]);
        });
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'tolerance_before_minutes' => 'integer',
            'tolerance_after_minutes' => 'integer',
            'rounding_minutes' => 'integer',
            'night_allowance_percent' => 'decimal:4',
            'sunday_allowance_percent' => 'decimal:4',
            'holiday_allowance_percent' => 'decimal:4',
            'daily_allowance_amount' => 'decimal:2',
            'daily_allowance_minimum_minutes' => 'integer',
        ];
    }
}
