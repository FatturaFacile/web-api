<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * One contract in the history of an employee: type, hours, collective agreement and level (references to the payroll
 * agreement tables, plain data), pay and its salary components. Only one contract per employee is active; the others
 * are amended (superseded_by_contract_id), expired or terminated.
 *
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $probation_end_date
 * @property string|null $collective_agreement_id
 * @property string|null $collective_agreement_level_id
 * @property string|null $level_code
 * @property string|null $superseded_by_contract_id
 * @property string|null $notes
 */
final class EmploymentContract extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_employment_contracts';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'probation_end_date' => 'date',
            'weekly_hours' => 'decimal:2',
            'fte_percentage' => 'decimal:2',
            'base_annual_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'loaded_hourly_cost' => 'decimal:2',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'terms' => 'array',
        ];
    }

    /**
     * Mark the contract as decided by its workflow.
     */
    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    /**
     * Whether a running workflow decides the contract.
     */
    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<JobGrade, $this>
     */
    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'job_grade_id');
    }

    /**
     * The recurring pay elements of the contract.
     *
     * @return HasMany<ContractSalaryComponent, $this>
     */
    public function salaryComponents(): HasMany
    {
        return $this->hasMany(ContractSalaryComponent::class, 'contract_id')->orderBy('valid_from')->orderBy('wage_type_code');
    }
}
