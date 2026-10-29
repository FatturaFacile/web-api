<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class Employee extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_employees';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'probation_end_date' => 'date',
            'confirmation_date' => 'date',
            'termination_date' => 'date',
            'rehire_date' => 'date',
            'base_annual_salary' => 'decimal:2',
            'hourly_cost_rate' => 'decimal:2',
            'fte_ratio' => 'decimal:2',
            'is_locked' => 'boolean',
            'emergency_contact' => 'array',
            'equipment_assigned' => 'array',
            'metadata' => 'array',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'approval_status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'job_grade_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(EmploymentContract::class, 'employee_id')->where('is_active', true);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'employee_id');
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(EmployeeLifecycleEvent::class, 'employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'employee_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class, 'employee_id');
    }

    public function employeeSkills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_id');
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(TrainingRecord::class, 'employee_id');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(Certification::class, 'employee_id');
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'employee_id');
    }
}
