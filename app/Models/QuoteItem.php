<?php

declare(strict_types=1);

namespace App\Models;

use Enterprise\MultiTenant\Concerns\StampsTenantFromParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $configuration_snapshot
 */
class QuoteItem extends Model
{
    use HasFactory, HasUuids, StampsTenantFromParent;

    protected $fillable = [
        'quote_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'discount_percentage',
        'line_total',
        'cost_price',
        'labor_cost',
        'margin_percentage',
        'configuration_snapshot',
        'is_optional',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'labor_cost' => 'decimal:4',
            'margin_percentage' => 'decimal:2',
            'configuration_snapshot' => 'array',
            'is_optional' => 'boolean',
            'is_selected' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
