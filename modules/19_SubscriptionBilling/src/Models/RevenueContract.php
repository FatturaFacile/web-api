<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RevenueContract extends BaseEnterpriseModel
{
    protected $table = 'sub_revenue_contracts';

    protected function casts(): array
    {
        return [
            'total_transaction_price' => 'decimal:2',
            'inception_date' => 'date',
            'termination_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function performanceObligations(): HasMany
    {
        return $this->hasMany(PerformanceObligation::class, 'revenue_contract_id');
    }
}
