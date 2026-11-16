<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sales target of a user (optionally for a territory) over a period; attainment is computed from the opportunities won in the period.
 */
final class SalesQuota extends BaseEnterpriseModel
{
    protected $table = 'crm_sales_quotas';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'target_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SalesTerritory, $this>
     */
    public function territory(): BelongsTo
    {
        return $this->belongsTo(SalesTerritory::class, 'territory_id');
    }
}
