<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Procurement\Enums\InvoiceMatchLineStatus;

/**
 * The comparison of one invoice line with its order line and the goods received: ordered, received and invoiced
 * quantities and unit prices with the exact variances and the allowances that applied.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string|null $passive_invoice_line_id
 * @property string|null $purchase_order_line_id
 * @property int $line_number
 * @property string|null $description
 * @property InvoiceMatchLineStatus $status
 * @property bool $receipt_required
 * @property string $ordered_quantity
 * @property string $received_quantity
 * @property string $previously_invoiced_quantity
 * @property string $invoiced_quantity
 * @property string $ordered_unit_price
 * @property string $invoiced_unit_price
 * @property string $quantity_variance
 * @property string $price_variance
 * @property string $price_variance_percent
 * @property string $amount_variance
 * @property string $allowed_quantity_variance
 * @property string $allowed_amount_variance
 * @property string $gr_ir_amount
 * @property string|null $tolerance_rule_id
 * @property list<string>|null $reasons
 */
final class InvoiceMatchLine extends BaseEnterpriseModel
{
    protected $table = 'invoice_match_lines';

    protected function casts(): array
    {
        return [
            'status' => InvoiceMatchLineStatus::class,
            'line_number' => 'integer',
            'receipt_required' => 'boolean',
            'ordered_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'previously_invoiced_quantity' => 'decimal:4',
            'invoiced_quantity' => 'decimal:4',
            'ordered_unit_price' => 'decimal:6',
            'invoiced_unit_price' => 'decimal:6',
            'quantity_variance' => 'decimal:4',
            'price_variance' => 'decimal:6',
            'price_variance_percent' => 'decimal:2',
            'amount_variance' => 'decimal:2',
            'allowed_quantity_variance' => 'decimal:4',
            'allowed_amount_variance' => 'decimal:2',
            'gr_ir_amount' => 'decimal:2',
            'reasons' => 'array',
        ];
    }

    /**
     * @return BelongsTo<InvoiceMatchingRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(InvoiceMatchingRun::class, 'run_id');
    }
}
