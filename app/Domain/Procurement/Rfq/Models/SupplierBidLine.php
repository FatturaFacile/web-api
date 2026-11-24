<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Individual line item offered inside a SupplierBid.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $supplier_quote_id
 * @property string $rfq_line_id
 * @property bool $is_no_bid
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_percent
 * @property string $net_unit_price
 * @property string $tax_rate
 * @property string $line_total
 * @property string $line_total_net
 * @property int|null $lead_time_days
 * @property Carbon|null $promised_delivery_date
 * @property string|null $supplier_notes
 * @property string|null $line_weighted_score
 * @property bool $is_awarded
 * @property-read SupplierBid|null $bid
 * @property-read RfqLine|null $rfqLine
 */
final class SupplierBidLine extends BaseEnterpriseModel
{
    protected $table = 'procurement_supplier_quote_lines';

    protected function casts(): array
    {
        return [
            'is_no_bid' => 'boolean',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'net_unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'line_total_net' => 'decimal:4',
            'lead_time_days' => 'integer',
            'promised_delivery_date' => 'date',
            'line_weighted_score' => 'decimal:2',
            'is_awarded' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SupplierBid, $this>
     */
    public function bid(): BelongsTo
    {
        return $this->belongsTo(SupplierBid::class, 'supplier_quote_id');
    }

    /**
     * @return BelongsTo<RfqLine, $this>
     */
    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RfqLine::class, 'rfq_line_id');
    }
}
