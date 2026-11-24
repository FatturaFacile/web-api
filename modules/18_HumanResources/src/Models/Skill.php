<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Skill extends BaseEnterpriseModel
{
    protected $table = 'hr_skills';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }

    public function employeeSkills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'skill_id');
    }
}
