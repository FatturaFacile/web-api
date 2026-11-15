<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An invoice received from a carrier. The audit compares each line with the expected rate of its transport order; a dispute tracks what is contested.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $carrier_id
 * @property string $invoice_number
 * @property Carbon $invoice_date
 * @property string $currency
 * @property string $total_amount
 * @property string $expected_total
 * @property string $variance_amount
 * @property string $tolerance_percent
 * @property string $audit_status
 * @property string $dispute_status
 * @property string|null $dispute_reason
 * @property string|null $resolution_note
 * @property string|null $credited_amount
 * @property Carbon|null $audited_at
 * @property string|null $notes
 */
final class TmsFreightInvoice extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array AuditStatuses = ['pending', 'matched', 'over_billed', 'under_billed', 'unmatched'];

    public const string Pending = 'pending';

    public const string Matched = 'matched';

    public const string OverBilled = 'over_billed';

    public const string UnderBilled = 'under_billed';

    public const string Unmatched = 'unmatched';

    /** @var list<string> */
    public const array DisputeStatuses = ['none', 'open', 'resolved_accepted', 'resolved_credited', 'rejected'];

    public const string None = 'none';

    public const string Open = 'open';

    public const string ResolvedAccepted = 'resolved_accepted';

    public const string ResolvedCredited = 'resolved_credited';

    public const string Rejected = 'rejected';

    protected $table = 'tms_freight_invoices';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'total_amount' => 'decimal:2',
            'expected_total' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'tolerance_percent' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'audited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TmsCarrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(TmsCarrier::class, 'carrier_id');
    }

    /**
     * @return HasMany<TmsFreightInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TmsFreightInvoiceLine::class, 'freight_invoice_id');
    }
}
