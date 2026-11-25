<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class SbcRecurringSubscription extends BaseEnterpriseModel
{
    protected $table = 'sbc_recurring_subscriptions';

    protected function casts(): array
    {
        return [
            'base_recurring_amount' => 'decimal:2',
            'pro_rata_adjustment_amount' => 'decimal:2',
            'last_index_rate_pct' => 'decimal:3',
            'revalued_amount' => 'decimal:2',
            'mrr_amount' => 'decimal:2',
            'arr_amount' => 'decimal:2',
            'next_billing_date' => 'date',
        ];
    }
}
