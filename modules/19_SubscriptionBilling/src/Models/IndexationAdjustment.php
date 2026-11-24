<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $base_month
 * @property Carbon $reference_month
 * @property Carbon $effective_date
 * @property Carbon|null $notified_at
 */
final class IndexationAdjustment extends BaseEnterpriseModel
{
    protected $table = 'sub_indexation_adjustments';

    protected function casts(): array
    {
        return [
            'base_month' => 'date',
            'reference_month' => 'date',
            'effective_date' => 'date',
            'base_index' => 'decimal:4',
            'new_index' => 'decimal:4',
            'variation_pct' => 'decimal:4',
            'applied_pct' => 'decimal:4',
            'old_recurring_amount' => 'decimal:2',
            'new_recurring_amount' => 'decimal:2',
            'old_mrr' => 'decimal:2',
            'new_mrr' => 'decimal:2',
            'notified_at' => 'datetime',
            'calculation_sheet' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
