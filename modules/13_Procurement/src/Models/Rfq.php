<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request for quotation sent to several suppliers: draft, issued, awarded (a purchase order was created from the
 * winning quote) or cancelled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $rfq_number
 * @property string $title
 * @property string|null $purchase_requisition_id
 * @property string $status
 * @property Carbon|null $issue_date
 * @property Carbon $response_deadline
 * @property string $currency
 * @property string|null $cost_center_id
 * @property string|null $awarded_supplier_id
 * @property string|null $awarded_quote_id
 * @property string|null $purchase_order_id
 * @property string|null $notes
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
final class Rfq extends BaseEnterpriseModel
{
    protected $table = 'procurement_rfqs';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'response_deadline' => 'date',
        ];
    }

    /**
     * @return HasMany<RfqLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class, 'rfq_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<RfqSupplier, $this>
     */
    public function invitedSuppliers(): HasMany
    {
        return $this->hasMany(RfqSupplier::class, 'rfq_id');
    }

    /**
     * @return HasMany<SupplierQuote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(SupplierQuote::class, 'rfq_id');
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }
}
