<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Return Inspection & Grading record at the returns station (Mod 232).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $inspection_number
 * @property string|null $customer_return_id
 * @property string|null $customer_return_line_id
 * @property string|null $rma_id
 * @property string|null $rma_line_id
 * @property string $product_id
 * @property string|null $serial_number
 * @property string|null $lot_number
 * @property string $quantity_inspected
 * @property string $grade
 * @property string $disposition
 * @property array<string, mixed>|null $checklist
 * @property array<int, string> $photo_urls
 * @property string $original_unit_cost
 * @property string $devaluation_percent
 * @property string $revalued_unit_cost
 * @property string|null $target_warehouse_id
 * @property string|null $supplier_id
 * @property string|null $supplier_return_id
 * @property string|null $refurbished_item_id
 * @property string|null $stock_movement_id
 * @property string|null $inspector_notes
 * @property string|null $inspected_by
 * @property Carbon $inspected_at
 */
final class ReturnGradingInspection extends BaseEnterpriseModel
{
    public const string GradeA = 'grade_a';

    public const string GradeB = 'grade_b';

    public const string GradeC = 'grade_c';

    public const string GradeScrapOrSupplier = 'scrap_or_supplier';

    /** @var list<string> */
    public const array Grades = [
        self::GradeA,
        self::GradeB,
        self::GradeC,
        self::GradeScrapOrSupplier,
    ];

    public const string DispositionRestockPrimary = 'restock_primary';

    public const string DispositionConvertBStock = 'convert_b_stock';

    public const string DispositionReturnToSupplier = 'return_to_supplier';

    public const string DispositionScrap = 'scrap';

    /** @var list<string> */
    public const array Dispositions = [
        self::DispositionRestockPrimary,
        self::DispositionConvertBStock,
        self::DispositionReturnToSupplier,
        self::DispositionScrap,
    ];

    protected $table = 'return_grading_inspections';

    protected function casts(): array
    {
        return [
            'quantity_inspected' => 'decimal:4',
            'checklist' => 'array',
            'photo_urls' => 'array',
            'original_unit_cost' => 'decimal:4',
            'devaluation_percent' => 'decimal:2',
            'revalued_unit_cost' => 'decimal:4',
            'inspected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<CustomerReturn, $this>
     */
    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class, 'customer_return_id');
    }

    /**
     * @return BelongsTo<RefurbishedItem, $this>
     */
    public function refurbishedItem(): BelongsTo
    {
        return $this->belongsTo(RefurbishedItem::class, 'refurbished_item_id');
    }
}
