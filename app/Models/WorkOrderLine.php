<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'work_order_id',
        'type',
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
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
