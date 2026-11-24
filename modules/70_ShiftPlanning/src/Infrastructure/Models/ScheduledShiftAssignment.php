<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;

/**
 * One shift of a roster. Without an employee it is an open shift that qualified staff can claim.
 *
 * @property string $roster_schedule_period_id
 * @property string|null $employee_id
 * @property CarbonImmutable $shift_date
 * @property string $shift_template_id
 * @property CarbonImmutable $start_at
 * @property CarbonImmutable $end_at
 * @property string|null $required_role_or_skill_id
 * @property string $estimated_labor_cost
 * @property array<string, mixed>|null $surcharge_breakdown
 * @property list<array<string, mixed>>|null $compliance_warnings
 */
final class ScheduledShiftAssignment extends BaseEnterpriseModel
{
    protected $table = 'roster_shift_assignments';

    protected function casts(): array
    {
        return [
            'shift_date' => 'immutable_date',
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'estimated_labor_cost' => 'decimal:2',
            'surcharge_breakdown' => 'array',
            'compliance_warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RosterSchedulePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(RosterSchedulePeriod::class, 'roster_schedule_period_id');
    }

    /**
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function isOpen(): bool
    {
        return $this->employee_id === null;
    }

    public function surcharges(): SurchargeBreakdown
    {
        return SurchargeBreakdown::fromArray($this->surcharge_breakdown);
    }

    /**
     * @return list<ComplianceIssue>
     */
    public function issues(): array
    {
        return array_map(static fn (array $issue): ComplianceIssue => ComplianceIssue::fromArray($issue), $this->compliance_warnings ?? []);
    }

    public function hasBlockingIssue(): bool
    {
        foreach ($this->issues() as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shift for the compliance rules, optionally for another employee (a claim, a swap).
     */
    public function toPlannedShift(?string $employeeId = null): PlannedShift
    {
        $template = $this->template;

        return new PlannedShift(
            $this->id,
            $employeeId ?? (string) $this->employee_id,
            $this->start_at,
            $this->end_at,
            $template->break_minutes ?? 0,
            $template->is_on_call ?? false,
            $template->night_surcharge_applicable ?? true,
        );
    }
}
