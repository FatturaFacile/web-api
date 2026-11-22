<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the analytical breakdown of a settlement proposal: a supplier invoice or credit note with the share of the
 * rebate it generated, a contribution, or the fixed bonus.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $settlement_id
 * @property string $line_type
 * @property string|null $passive_invoice_id
 * @property string|null $reference
 * @property Carbon|null $reference_date
 * @property string $net_amount
 * @property string $rebate_amount
 */
final class RebateSettlementLine extends BaseEnterpriseModel
{
    public const string Invoice = 'invoice';

    public const string CreditNote = 'credit_note';

    public const string Contribution = 'contribution';

    public const string Bonus = 'bonus';

    protected $table = 'rebate_settlement_lines';

    protected function casts(): array
    {
        return ['reference_date' => 'date', 'net_amount' => 'decimal:2', 'rebate_amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<RebateSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RebateSettlement::class, 'settlement_id');
    }
}
