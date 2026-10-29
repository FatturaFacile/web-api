<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Position extends BaseEnterpriseModel
{
    protected $table = 'hr_positions';

    protected function casts(): array
    {
        return [
            'headcount_budget' => 'integer',
            'is_critical_role' => 'boolean',
            'is_active' => 'boolean',
            'translations' => 'array',
            'competencies_required' => 'array',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'job_grade_id');
    }

    public function reportsToPosition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_position_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'successor_employee_id');
    }

    public function incumbents(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }
}
