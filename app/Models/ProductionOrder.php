<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'production_orders';

    protected $fillable = [
        'tenant_id',
        'bill_of_materials_id',
        'order_number',
        'target_quantity',
        'produced_quantity',
        'start_date',
        'due_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'decimal:4',
            'produced_quantity' => 'decimal:4',
            'start_date' => 'date',
            'due_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<BillOfMaterial, $this>
     */
    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_materials_id');
    }

    /**
     * @return HasMany<ProductionOrderStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ProductionOrderStage::class, 'production_order_id');
    }
}
