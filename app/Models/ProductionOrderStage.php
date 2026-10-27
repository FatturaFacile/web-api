<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderStage extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'production_order_stages';

    protected $fillable = [
        'production_order_id',
        'stage_name',
        'sequence',
        'planned_hours',
        'actual_hours',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'planned_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
