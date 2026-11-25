<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refurbished / B-Stock SKU inventory item generated from Grade B/C return grading (Mod 232).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $refurbished_sku
 * @property string $original_product_id
 * @property string|null $refurbished_product_id
 * @property string|null $return_grading_inspection_id
 * @property string|null $customer_return_id
 * @property string|null $rma_id
 * @property string $grade
 * @property string|null $serial_number
 * @property string|null $lot_number
 * @property string $quantity
 * @property string $original_cost
 * @property string $devaluation_percent
 * @property string $refurbishment_cost
 * @property string $new_unit_cost
 * @property string $suggested_sale_price
 * @property string|null $warehouse_id
 * @property string $work_order_status
 * @property array<string, mixed>|null $work_order_steps
 * @property string|null $stock_movement_id
 * @property bool $is_available
 * @property string|null $notes
 * @property string|null $created_by
 */
final class RefurbishedItem extends BaseEnterpriseModel
{
    public const string WorkOrderPending = 'pending_reconditioning';

    public const string WorkOrderInProgress = 'in_progress';

    public const string WorkOrderCompleted = 'completed';

    /** @var list<string> */
    public const array WorkOrderStatuses = [
        self::WorkOrderPending,
        self::WorkOrderInProgress,
        self::WorkOrderCompleted,
    ];

    protected $table = 'refurbished_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'original_cost' => 'decimal:4',
            'devaluation_percent' => 'decimal:2',
            'refurbishment_cost' => 'decimal:4',
            'new_unit_cost' => 'decimal:4',
            'suggested_sale_price' => 'decimal:4',
            'work_order_steps' => 'array',
            'is_available' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'original_product_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function refurbishedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'refurbished_product_id');
    }
}
