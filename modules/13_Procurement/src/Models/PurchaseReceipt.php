<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A goods receipt note against a purchase order. A receipt that needs a quality inspection keeps its goods in a
 * quarantine bin until the inspection releases or rejects them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $purchase_order_id
 * @property string $receipt_number
 * @property string|null $supplier_delivery_note_ref
 * @property Carbon|null $received_date
 * @property string $received_by_user_id
 * @property string|null $warehouse_id
 * @property string|null $inspection_status
 * @property bool $inspection_required
 * @property Carbon|null $inspected_at
 * @property string|null $inspected_by
 * @property string|null $landed_cost_amount
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
final class PurchaseReceipt extends BaseEnterpriseModel
{
    protected $table = 'purchase_receipts';

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'inspection_required' => 'boolean',
            'inspected_at' => 'datetime',
            'landed_cost_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * @return HasMany<PurchaseReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class, 'purchase_receipt_id');
    }
}
