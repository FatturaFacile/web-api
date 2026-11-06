<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\HumanResources\Services\TimeTracking\TimeRecordingMode;

/**
 * How the working time of an employee is recorded, and the contract data it is evaluated against.
 *
 * @property TimeRecordingMode $mode
 */
final class TimeRecordingProfile extends BaseEnterpriseModel
{
    protected $table = 'time_recording_profiles';

    protected $attributes = [
        'mode' => 'Detailed',
        'legal_max_weekly_hours' => 45,
        'weekly_hours' => '42.00',
        'work_days_per_week' => 5,
    ];

    protected function casts(): array
    {
        return [
            'mode' => TimeRecordingMode::class,
            'agreement_date' => 'date',
            'agreement_valid_from' => 'date',
            'agreement_valid_to' => 'date',
            'autonomy_percent' => 'integer',
            'annual_gross_salary' => 'encrypted',
            'legal_max_weekly_hours' => 'integer',
            'weekly_hours' => 'decimal:2',
            'work_days_per_week' => 'integer',
            'hire_date' => 'date',
            'termination_date' => 'date',
        ];
    }

    /**
     * The values kept in the history of the changes, which is immutable: the salary itself never enters it.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'mode' => $this->mode->value,
            'agreement_date' => $this->agreement_date?->format('Y-m-d'),
            'agreement_valid_from' => $this->agreement_valid_from?->format('Y-m-d'),
            'agreement_valid_to' => $this->agreement_valid_to?->format('Y-m-d'),
            'agreement_basis' => $this->agreement_basis,
            'autonomy_percent' => $this->autonomy_percent,
            'collective_agreement_reference' => $this->collective_agreement_reference,
            'salary_threshold_met' => $this->annual_gross_salary !== null,
            'legal_max_weekly_hours' => $this->legal_max_weekly_hours,
            'weekly_hours' => $this->weekly_hours,
            'work_days_per_week' => $this->work_days_per_week,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'canton' => $this->canton,
        ];
    }
}
