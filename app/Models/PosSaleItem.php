<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSaleItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'pos_sale_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'line_total' => 'decimal:2',
            'quantity' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'unit_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PosSale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }
}
