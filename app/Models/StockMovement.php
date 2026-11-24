<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'warehouse_id',
        'type',
        'quantity',
        'unit_cost',
        'reference_type',
        'reference_id',
        'notes',
    ];

    /**
     * `signed_quantity` is added to the serialized movement: single-ledger movements store an unsigned quantity.
     *
     * @var list<string>
     */
    protected $appends = ['signed_quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * The app's signed view of the movement: negative when stock leaves. Movements of the inventory module's ledger
     * (stock item set) carry an unsigned quantity whose direction is given by the source and destination bins; legacy
     * app rows keep their sign (`out` positive but outbound, `adjustment` signed).
     */
    public function getSignedQuantityAttribute(): float
    {
        $quantity = (float) $this->getAttribute('quantity');

        if ($this->getAttribute('stock_item_id') !== null) {
            $leaves = $this->getAttribute('source_location_id') !== null && $this->getAttribute('destination_location_id') === null;

            return $leaves ? -abs($quantity) : abs($quantity);
        }

        return $this->getAttribute('type') === 'out' ? -$quantity : $quantity;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
