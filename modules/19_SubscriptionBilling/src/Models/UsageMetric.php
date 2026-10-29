<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class UsageMetric extends BaseEnterpriseModel
{
    protected $table = 'sub_usage_metrics';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'metric_id');
    }
}
