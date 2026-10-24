<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'quote_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'discount_percentage',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'unit_price' => 'decimal:4',
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
