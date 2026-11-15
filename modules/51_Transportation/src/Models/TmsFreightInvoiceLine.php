<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A billed amount of a carrier invoice, linked to the transport order it pays, with the variance found by the audit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $freight_invoice_id
 * @property string|null $transport_order_id
 * @property string|null $description
 * @property string $billed_amount
 * @property string|null $expected_amount
 * @property string|null $variance_amount
 * @property string|null $variance_percent
 * @property string $audit_status
 */
final class TmsFreightInvoiceLine extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array AuditStatuses = ['pending', 'matched', 'over_billed', 'under_billed', 'unmatched'];

    public const string Pending = 'pending';

    public const string Matched = 'matched';

    public const string OverBilled = 'over_billed';

    public const string UnderBilled = 'under_billed';

    public const string Unmatched = 'unmatched';

    protected $table = 'tms_freight_invoice_lines';

    protected function casts(): array
    {
        return [
            'billed_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'variance_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<TmsTransportOrder, $this>
     */
    public function transportOrder(): BelongsTo
    {
        return $this->belongsTo(TmsTransportOrder::class, 'transport_order_id');
    }
}
