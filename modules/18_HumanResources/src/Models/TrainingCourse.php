<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingCourse extends BaseEnterpriseModel
{
    protected $table = 'hr_training_courses';

    protected function casts(): array
    {
        return [
            'duration_hours' => 'decimal:2',
            'cost_per_participant' => 'decimal:2',
            'validity_months' => 'integer',
            'is_mandatory_compliance' => 'boolean',
            'is_active' => 'boolean',
            'translations' => 'array',
            'syllabus' => 'array',
        ];
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(TrainingRecord::class, 'course_id');
    }
}
