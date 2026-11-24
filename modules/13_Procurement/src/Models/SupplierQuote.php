<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The quotation of a supplier in answer to a request for quotation: received, awarded or rejected.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rfq_id
 * @property string $supplier_id
 * @property string|null $quote_reference
 * @property Carbon $quote_date
 * @property Carbon|null $valid_until
 * @property string $currency
 * @property int|null $lead_time_days
 * @property string $status
 * @property string $total_amount
 * @property string|null $notes
 * @property Supplier|null $supplier
 */
final class SupplierQuote extends BaseEnterpriseModel
{
    protected $table = 'procurement_supplier_quotes';

    protected function casts(): array
    {
        return [
            'quote_date' => 'date',
            'valid_until' => 'date',
            'lead_time_days' => 'integer',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<SupplierQuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierQuoteLine::class, 'supplier_quote_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }
}
