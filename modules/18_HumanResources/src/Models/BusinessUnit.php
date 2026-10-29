<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BusinessUnit extends BaseEnterpriseModel
{
    protected $table = 'hr_business_units';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function headEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'business_unit_id');
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'business_unit_id');
    }
}
