<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A return of received goods to the supplier, with the request of a credit note.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $purchase_order_id
 * @property string|null $purchase_receipt_id
 * @property string $supplier_id
 * @property string $return_number
 * @property Carbon|null $return_date
 * @property string $returned_by_user_id
 * @property string $status
 * @property string|null $total_amount
 * @property string|null $reason
 * @property string|null $notes
 * @property Carbon|null $credit_note_requested_at
 * @property string|null $credit_note_reference
 * @property string|null $credit_note_passive_invoice_id
 * @property string|null $journal_entry_id
 */
final class PurchaseReturn extends BaseEnterpriseModel
{
    protected $table = 'purchase_returns';

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'total_amount' => 'decimal:2',
            'credit_note_requested_at' => 'datetime',
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
     * @return BelongsTo<PurchaseReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }

    /**
     * @return HasMany<PurchaseReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'purchase_return_id');
    }
}
