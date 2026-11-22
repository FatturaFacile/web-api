<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SLAPolicy extends BaseEnterpriseModel
{
    protected $table = 'esm_sla_policies';

    protected function casts(): array
    {
        return [
            'response_time_minutes' => 'integer',
            'resolution_time_minutes' => 'integer',
            'escalation_time_minutes' => 'integer',
            'business_hours_only' => 'boolean',
            'next_update_interval_minutes' => 'integer',
            'pause_on_on_hold' => 'boolean',
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function supportPlan(): BelongsTo
    {
        return $this->belongsTo(SupportPlan::class, 'support_plan_id');
    }
}
