<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The capacity and bookings of an employee in one week (Monday start), recomputed whenever a booking changes.
 *
 * @property string $employee_id
 * @property CarbonImmutable $week_start_date
 * @property numeric-string $contractual_hours
 * @property numeric-string $leave_and_holiday_hours
 * @property numeric-string $net_available_hours
 * @property numeric-string $billable_booked_hours
 * @property numeric-string $billable_committed_hours
 * @property numeric-string $internal_booked_hours
 * @property numeric-string $tentative_booked_hours
 * @property numeric-string $utilization_rate_percent
 * @property string|null $department_id
 * @property string|null $cost_center_id
 */
final class ResourceCapacitySnapshot extends BaseEnterpriseModel
{
    protected $table = 'rp_resource_capacity_snapshots';

    protected function casts(): array
    {
        return [
            'week_start_date' => 'immutable_date',
            'contractual_hours' => 'decimal:2',
            'leave_and_holiday_hours' => 'decimal:2',
            'net_available_hours' => 'decimal:2',
            'billable_booked_hours' => 'decimal:2',
            'billable_committed_hours' => 'decimal:2',
            'internal_booked_hours' => 'decimal:2',
            'tentative_booked_hours' => 'decimal:2',
            'utilization_rate_percent' => 'decimal:2',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
