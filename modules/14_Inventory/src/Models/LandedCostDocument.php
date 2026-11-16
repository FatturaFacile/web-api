<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\LandedCostStatus;

/**
 * Landed cost document (freight, customs duty, insurance, brokerage) allocated onto goods receipts.
 * Applied/reversed documents are immutable; corrections are reversal + new document.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $number
 * @property string $status
 * @property string $cost_type
 * @property string $allocation_key
 * @property string $currency
 * @property string $exchange_rate
 * @property string $total_amount
 * @property string|null $supplier_invoice_id
 * @property \Illuminate\Support\Carbon $document_date
 * @property string|null $notes
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon|null $applied_at
 * @property string|null $applied_by
 * @property string|null $journal_entry_id
 * @property string|null $revaluation_id
 * @property string|null $reversal_of_id
 */
final class LandedCostDocument extends BaseEnterpriseModel
{
    protected $table = 'landed_cost_documents';

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'applied_at' => 'datetime',
            'reversed_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
        ];
    }

    /**
     * @return HasMany<LandedCostDocumentReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(LandedCostDocumentReceipt::class, 'landed_cost_document_id');
    }

    /**
     * @return HasMany<LandedCostAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class, 'landed_cost_document_id');
    }

    /**
     * @return BelongsTo<InventoryRevaluation, $this>
     */
    public function revaluation(): BelongsTo
    {
        return $this->belongsTo(InventoryRevaluation::class, 'revaluation_id');
    }

    /**
     * @return BelongsTo<LandedCostDocument, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function isDraft(): bool
    {
        return $this->status === LandedCostStatus::Draft->value;
    }

    /**
     * Goods receipt numbers keyed by receipt id (read-only lookup on Procurement's table; no model coupling).
     *
     * @return array<string, string>
     */
    public function receiptNumbers(): array
    {
        $ids = $this->receiptIds();
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $numbers */
        $numbers = DB::table('purchase_receipts')->whereIn('id', $ids)->pluck('receipt_number', 'id')->all();

        return $numbers;
    }

    /**
     * @return list<string>
     */
    public function receiptIds(): array
    {
        return $this->receipts()->pluck('purchase_receipt_id')->map(static fn ($id): string => (string) $id)->values()->all();
    }
}
