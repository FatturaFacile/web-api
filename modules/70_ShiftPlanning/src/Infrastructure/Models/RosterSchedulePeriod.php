<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;

/**
 * Aggregate root of a roster: the weekly or monthly plan of a location, department or production line (Mod 061).
 *
 * @property string|null $location_or_department_id
 * @property string|null $production_line_id
 * @property PeriodType $period_type
 * @property Jurisdiction $jurisdiction
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property RosterStatus $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $locked_at
 */
final class RosterSchedulePeriod extends BaseEnterpriseModel
{
    protected $table = 'roster_schedule_periods';

    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'jurisdiction' => Jurisdiction::class,
            'status' => RosterStatus::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'published_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ScheduledShiftAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ScheduledShiftAssignment::class, 'roster_schedule_period_id');
    }

    public function containsDate(CarbonImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->start_date->format('Y-m-d') && $day <= $this->end_date->format('Y-m-d');
    }

    /**
     * The period must end after it starts and span the days its type promises: seven for a week (Monday to Sunday), one
     * calendar month for a month.
     *
     * @throws ShiftPlanningException
     */
    public function assertIntegrity(): void
    {
        $days = (int) $this->start_date->diffInDays($this->end_date) + 1;

        $valid = match ($this->period_type) {
            PeriodType::Weekly => $days === 7,
            PeriodType::Monthly => $this->start_date->day === 1 && $this->end_date->format('Y-m-d') === $this->start_date->endOfMonth()->format('Y-m-d'),
        };

        if (! $valid) {
            throw new ShiftPlanningException("The {$this->period_type->value} period {$this->start_date->format('Y-m-d')} - {$this->end_date->format('Y-m-d')} is not well formed.");
        }
    }

    /**
     * @throws ShiftPlanningException
     */
    public function assertEditable(): void
    {
        if (! $this->status->isEditable()) {
            throw new ShiftPlanningException('The roster is locked and can no longer be changed.');
        }
    }

    /**
     * @throws ShiftPlanningException
     */
    public function publish(string $userId): void
    {
        if ($this->status !== RosterStatus::Draft) {
            throw new ShiftPlanningException('Only a draft roster can be published.');
        }

        $this->assertIntegrity();
        $this->update(['status' => RosterStatus::Published, 'published_at' => CarbonImmutable::now(), 'published_by' => $userId]);
    }

    /**
     * @throws ShiftPlanningException
     */
    public function lock(string $userId): void
    {
        if ($this->status !== RosterStatus::Published) {
            throw new ShiftPlanningException('Only a published roster can be locked.');
        }

        $this->update(['status' => RosterStatus::Locked, 'locked_at' => CarbonImmutable::now(), 'locked_by' => $userId]);
    }
}
