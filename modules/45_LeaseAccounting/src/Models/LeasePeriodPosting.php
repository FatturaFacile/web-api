<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The journal of a period. A contract has at most one posting per period (unique index), which makes the posting
 * idempotent.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lease_contract_id
 * @property string $schedule_line_id
 * @property int $period_number
 * @property Carbon $posting_date
 * @property string $journal_entry_id
 * @property string $interest
 * @property string $depreciation
 * @property string $payment
 * @property Carbon $posted_at
 * @property string|null $posted_by
 */
final class LeasePeriodPosting extends BaseEnterpriseModel
{
    protected $table = 'lease_period_postings';

    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'posting_date' => 'date',
            'interest' => 'decimal:2',
            'depreciation' => 'decimal:2',
            'payment' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LeaseContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class, 'lease_contract_id');
    }
}
