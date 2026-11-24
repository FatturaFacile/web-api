<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ResourcePlanning\Domain\Enums\BookingType;
use Modules\ResourcePlanning\Domain\ValueObjects\PlannedBooking;

/**
 * Hours per day of an employee booked on a project (and WBS node) or held for a CRM opportunity.
 *
 * @property string|null $project_id
 * @property string|null $wbs_node_id
 * @property string $employee_id
 * @property string|null $role_id
 * @property BookingType $booking_type
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property numeric-string $allocated_hours_per_day
 * @property numeric-string $total_allocated_hours
 * @property bool $is_billable
 * @property string|null $crm_opportunity_id
 * @property bool $is_overloaded
 * @property numeric-string $peak_load_percent
 * @property string|null $notes
 * @property string|null $created_by
 * @property CarbonImmutable|null $converted_to_hard_at
 */
final class ResourceAllocation extends BaseEnterpriseModel
{
    protected $table = 'rp_resource_allocations';

    protected function casts(): array
    {
        return [
            'booking_type' => BookingType::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'allocated_hours_per_day' => 'decimal:2',
            'total_allocated_hours' => 'decimal:2',
            'is_billable' => 'boolean',
            'is_overloaded' => 'boolean',
            'peak_load_percent' => 'decimal:2',
            'converted_to_hard_at' => 'immutable_datetime',
        ];
    }

    public function toBooking(): PlannedBooking
    {
        return new PlannedBooking($this->employee_id, $this->start_date, $this->end_date, (float) $this->allocated_hours_per_day, $this->booking_type, $this->is_billable);
    }
}
