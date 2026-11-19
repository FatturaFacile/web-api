<?php

declare(strict_types=1);

namespace App\Models\RecurringContracts;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\VatCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringContractLine extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'recurring_contract_lines';

    protected $fillable = [
        'recurring_contract_id',
        'product_id',
        'sort_order',
        'description',
        'quantity',
        'unit_price',
        'discount_percent',
        'vat_rate_id',
        'tax_rate',
        'proration_mode',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<RecurringContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(RecurringContract::class, 'recurring_contract_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<VatCode, $this>
     */
    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatCode::class, 'vat_rate_id');
    }

    /**
     * Compute line subtotal (quantity * unit_price * (1 - discount/100)) with bcmath.
     */
    public function calculateLineSubtotal(): string
    {
        $qty = (string) $this->quantity;
        $price = (string) $this->unit_price;
        $discount = (string) ($this->discount_percent ?? '0.00');

        $base = bcmul($qty, $price, 4);
        if (bccomp($discount, '0.00', 2) > 0) {
            $discountFactor = bcsub('1.0000', bcdiv($discount, '100', 4), 4);

            return bcmul($base, $discountFactor, 4);
        }

        return $base;
    }

    /**
     * Compute line tax amount with bcmath.
     */
    public function calculateLineTax(): string
    {
        $subtotal = $this->calculateLineSubtotal();
        $taxRate = (string) ($this->tax_rate ?? '0.00');

        return bcmul($subtotal, bcdiv($taxRate, '100', 4), 4);
    }

    /**
     * Compute line total with bcmath.
     */
    public function calculateLineTotal(): string
    {
        return bcadd($this->calculateLineSubtotal(), $this->calculateLineTax(), 4);
    }
}
