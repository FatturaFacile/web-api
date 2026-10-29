<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CostCenter extends BaseEnterpriseModel
{
    protected $table = 'hr_cost_centers';

    protected function casts(): array
    {
        return [
            'annual_budget' => 'decimal:2',
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'cost_center_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'cost_center_id');
    }
}
