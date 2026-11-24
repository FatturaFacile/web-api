<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one supplier payment carried of one withholding type.
 */
final class WithholdingSettlementLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'withholding_settlement_id', 'passive_invoice_id', 'supplier_payment_id', 'supplier_id', 'invoice_number',
        'paid_at', 'withholding_type', 'reason', 'f24_code', 'gross_paid', 'taxable_amount', 'rate_percent', 'withheld_amount',
        'detail_assumed',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'date',
            'detail_assumed' => 'boolean',
            'gross_paid' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'rate_percent' => 'decimal:2',
            'withheld_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }
}
