<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price a supplier quoted for one line of a request for quotation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_quote_id
 * @property string $rfq_line_id
 * @property string $quantity
 * @property string $unit_price
 * @property string $tax_rate
 * @property string $line_total
 * @property int|null $lead_time_days
 * @property RfqLine|null $rfqLine
 */
final class SupplierQuoteLine extends BaseEnterpriseModel
{
    protected $table = 'procurement_supplier_quote_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'lead_time_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RfqLine, $this>
     */
    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RfqLine::class, 'rfq_line_id');
    }
}
