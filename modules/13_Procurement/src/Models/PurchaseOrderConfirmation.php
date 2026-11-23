<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A supplier order confirmation recorded against a purchase order, with the totals of its variances.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_order_id
 * @property string $confirmation_number
 * @property Carbon $confirmed_at
 * @property string|null $confirmed_by
 * @property bool $has_variance
 * @property bool $requires_review
 * @property string $price_variance_total
 * @property string $quantity_variance_total
 * @property string|null $notes
 */
final class PurchaseOrderConfirmation extends BaseEnterpriseModel
{
    protected $table = 'purchase_order_confirmations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'date',
            'has_variance' => 'boolean',
            'requires_review' => 'boolean',
            'price_variance_total' => 'decimal:2',
            'quantity_variance_total' => 'decimal:4',
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
     * @return HasMany<PurchaseOrderConfirmationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderConfirmationLine::class, 'purchase_order_confirmation_id');
    }
}
