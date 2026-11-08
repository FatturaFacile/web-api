<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The IFRS 15 revenue contract of a subscription.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_number
 * @property string $subscription_id
 * @property string $currency
 * @property Carbon|null $inception_date
 * @property Carbon|null $termination_date
 * @property string $status
 * @property Carbon|null $created_at
 */
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

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * @return HasMany<PerformanceObligation, $this>
     */
    public function performanceObligations(): HasMany
    {
        return $this->hasMany(PerformanceObligation::class, 'revenue_contract_id');
    }
}
