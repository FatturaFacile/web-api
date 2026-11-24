<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Team extends BaseEnterpriseModel
{
    protected $table = 'hr_teams';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Employee::class, 'team_id');
    }
}
