<?php

declare(strict_types=1);

namespace Modules\DownPayments\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A negative deduction line of a final settlement invoice: what it takes back of one advance invoice at one VAT rate.
 *
 * @property string $chain_id
 * @property string $final_invoice_id
 * @property string|null $final_invoice_item_id
 * @property string|null $referenced_link_id
 * @property string|null $referenced_invoice_number
 * @property Carbon|null $referenced_invoice_date
 * @property string|null $vat_rate_id
 * @property string $vat_percentage
 * @property string $deducted_net
 * @property string $deducted_vat
 * @property string $deducted_gross
 */
final class FinalSettlementDeductionLine extends BaseEnterpriseModel
{
    protected $table = 'final_settlement_deduction_lines';

    protected function casts(): array
    {
        return [
            'referenced_invoice_date' => 'date:Y-m-d',
            'vat_percentage' => 'decimal:3',
            'deducted_net' => 'decimal:2',
            'deducted_vat' => 'decimal:2',
            'deducted_gross' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DownPaymentLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(DownPaymentLink::class, 'referenced_link_id');
    }
}
