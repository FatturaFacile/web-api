<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupportPlan extends BaseEnterpriseModel
{
    protected $table = 'esm_support_plans';

    protected function casts(): array
    {
        return [
            'response_time_hours' => 'decimal:2',
            'resolution_time_hours' => 'decimal:2',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function serviceContracts(): HasMany
    {
        return $this->hasMany(ServiceContract::class, 'support_plan_id');
    }

    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SLAPolicy::class, 'support_plan_id');
    }
}
