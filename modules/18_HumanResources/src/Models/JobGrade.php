<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class JobGrade extends BaseEnterpriseModel
{
    protected $table = 'hr_job_grades';

    protected function casts(): array
    {
        return [
            'level_rank' => 'integer',
            'min_salary' => 'decimal:2',
            'midpoint_salary' => 'decimal:2',
            'max_salary' => 'decimal:2',
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'job_grade_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'job_grade_id');
    }
}
