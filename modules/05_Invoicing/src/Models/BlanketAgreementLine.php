<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BlanketAgreementLine extends BaseEnterpriseModel
{
    protected $table = 'blanket_agreement_lines';

    protected function casts(): array
    {
        return [
            'target_quantity' => 'decimal:4',
            'called_off_quantity' => 'decimal:4',
            'delivered_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'target_amount' => 'decimal:4',
            'called_off_amount' => 'decimal:4',
            'delivery_schedule' => 'array',
        ];
    }

    /**
     * @return BelongsTo<BlanketAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(BlanketAgreement::class, 'blanket_agreement_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Quantity not yet called off, never negative (an over-release within tolerance reads as zero).
     */
    public function getRemainingQuantityAttribute(): string
    {
        $remaining = bcsub((string) $this->target_quantity, (string) $this->called_off_quantity, 4);

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }
}
