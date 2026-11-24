<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\PassiveInvoice;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\InvoiceMatchType;

/**
 * One evaluation of a supplier invoice against its purchase order (2-way) and goods receipts (3-way). Only the latest
 * run of an invoice is current; the earlier ones stay as history.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $passive_invoice_id
 * @property string|null $purchase_order_id
 * @property string|null $supplier_id
 * @property InvoiceMatchType $match_type
 * @property InvoiceMatchStatus $status
 * @property string $trigger
 * @property string $reference_source
 * @property string|null $reference_value
 * @property string|null $currency
 * @property string $ordered_total
 * @property string $received_total
 * @property string $invoiced_total
 * @property string $variance_amount
 * @property string $variance_percent
 * @property string $gr_ir_amount
 * @property string $price_variance_amount
 * @property int $line_count
 * @property int $discrepancy_count
 * @property bool $is_current
 * @property list<string>|null $reasons
 * @property array<string, mixed>|null $tolerance_snapshot
 * @property Carbon $evaluated_at
 * @property string|null $evaluated_by
 * @property Carbon|null $blocked_at
 * @property Carbon|null $released_at
 */
final class InvoiceMatchingRun extends BaseEnterpriseModel
{
    protected $table = 'invoice_matching_runs';

    protected function casts(): array
    {
        return [
            'match_type' => InvoiceMatchType::class,
            'status' => InvoiceMatchStatus::class,
            'ordered_total' => 'decimal:2',
            'received_total' => 'decimal:2',
            'invoiced_total' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'variance_percent' => 'decimal:2',
            'gr_ir_amount' => 'decimal:2',
            'price_variance_amount' => 'decimal:2',
            'line_count' => 'integer',
            'discrepancy_count' => 'integer',
            'is_current' => 'boolean',
            'reasons' => 'array',
            'tolerance_snapshot' => 'array',
            'evaluated_at' => 'datetime',
            'blocked_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class, 'passive_invoice_id');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<InvoiceMatchLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceMatchLine::class, 'run_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<InvoiceMatchResolution, $this>
     */
    public function resolutions(): HasMany
    {
        return $this->hasMany(InvoiceMatchResolution::class, 'run_id')->orderBy('resolved_at');
    }
}
