<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The commissions of an agent for a period. Approving it accrues the payable in the ledger.
 */
final class SalesCommissionStatement extends BaseEnterpriseModel
{
    public const string StatusDraft = 'draft';

    public const string StatusApproved = 'approved';

    public const string StatusPaid = 'paid';

    public const string LedgerNotPosted = 'not_posted';

    public const string LedgerPending = 'pending';

    public const string LedgerPosted = 'posted';

    protected $table = 'sales_commission_statements';

    /**
     * @return HasMany<SalesCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(SalesCommission::class, 'commission_statement_id');
    }

    /**
     * @return BelongsTo<SalesRepresentative, $this>
     */
    public function representative(): BelongsTo
    {
        return $this->belongsTo(SalesRepresentative::class, 'sales_representative_id');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'total_base' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
