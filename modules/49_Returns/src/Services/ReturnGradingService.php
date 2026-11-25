<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\CustomerReturnLine;
use Modules\Returns\Models\RefurbishedItem;
use Modules\Returns\Models\ReturnGradingInspection;
use Modules\Returns\Models\ReturnsSetting;
use Modules\Returns\Support\Decimal;
use Modules\Returns\Support\ReturnsError;

/**
 * Return Inspection, Grading (A/B/C/Scrap) & B-Stock Refurbished Conversion Service (Mod 232).
 * Enforces mandatory photo evidence, handles primary restock (Grade A), Outlet/Refurbished SKU conversion
 * with cost devaluation (Grade B/C), and Scrap / Supplier Return (RTV).
 */
final readonly class ReturnGradingService
{
    public function __construct(
        private ReturnsNumbering $numbering,
        private ReturnsSettingsService $settings,
        private ReturnsStockBooker $stock,
        private SupplierReturnService $supplierReturns,
        private ReturnsLedger $ledger,
    ) {}

    /**
     * Record a return inspection and grade at the returns bench.
     *
     * @param  array<string, mixed>  $data
     */
    public function inspectAndGrade(string $tenantId, array $data, ?string $userId): ReturnGradingInspection
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): ReturnGradingInspection {
            $photos = array_values(array_filter((array) ($data['photo_urls'] ?? []), static fn (mixed $v): bool => is_string($v) && trim($v) !== ''));
            if ($photos === []) {
                throw ReturnsError::on('photo_urls', 'grading.photos_required');
            }

            $product = Product::withTrashed()->where('tenant_id', $tenantId)->whereKey($data['product_id'])->first();
            if ($product === null) {
                throw ReturnsError::on('product_id', 'warranty.product_unknown');
            }

            $grade = (string) $data['grade'];
            $disposition = (string) $data['disposition'];
            $quantity = Decimal::round($data['quantity_inspected'] ?? 1, 4);
            if (! Decimal::isPositive($quantity)) {
                throw ReturnsError::on('quantity_inspected', 'quantity_positive');
            }

            $setting = $this->settings->settings($tenantId);
            $originalCost = Decimal::round($data['original_unit_cost'] ?? $product->cost_price ?? '0', 4);
            $devaluationPercent = match ($grade) {
                ReturnGradingInspection::GradeB => Decimal::round($data['devaluation_percent'] ?? $setting->outlet_devaluation_grade_b_percent ?? ReturnsSetting::DefaultDevaluationGradeBPercent, 2),
                ReturnGradingInspection::GradeC => Decimal::round($data['devaluation_percent'] ?? $setting->outlet_devaluation_grade_c_percent ?? ReturnsSetting::DefaultDevaluationGradeCPercent, 2),
                default => '0.00',
            };
            $revaluedCost = Decimal::round(
                Decimal::mul($originalCost, Decimal::sub('1', Decimal::div($devaluationPercent, '100'))),
                4
            );

            $inspectionNumber = $this->numbering->next(ReturnGradingInspection::class, 'inspection_number', 'GRD', $tenantId);
            $targetWarehouseId = $data['target_warehouse_id'] ?? null;
            $stockMovementId = null;
            $refurbishedItem = null;
            $supplierReturnId = null;

            if ($grade === ReturnGradingInspection::GradeA || $disposition === ReturnGradingInspection::DispositionRestockPrimary) {
                $warehouse = $this->stock->warehouse($tenantId, $targetWarehouseId, 'target_warehouse_id');
                $targetWarehouseId = $warehouse->id;
                $stockMovementId = $this->stock->receive(
                    (string) $product->id,
                    $quantity,
                    $warehouse,
                    $product,
                    __('returns_errors.grading.restock_note', ['number' => $inspectionNumber])
                );
            } elseif (in_array($grade, [ReturnGradingInspection::GradeB, ReturnGradingInspection::GradeC], true) || $disposition === ReturnGradingInspection::DispositionConvertBStock) {
                $warehouse = $this->resolveOutletWarehouse($tenantId, $targetWarehouseId ?? $setting->outlet_warehouse_id);
                $targetWarehouseId = $warehouse->id;
                $refurbishedItem = $this->createRefurbishedBStock(
                    $tenantId,
                    $product,
                    $grade,
                    $quantity,
                    $originalCost,
                    $devaluationPercent,
                    $revaluedCost,
                    $warehouse,
                    $data,
                    $userId
                );
                $stockMovementId = $refurbishedItem->stock_movement_id;
            } elseif ($disposition === ReturnGradingInspection::DispositionReturnToSupplier) {
                $supplierId = (string) ($data['supplier_id'] ?? '');
                if ($supplierId === '' || ! Supplier::query()->where('tenant_id', $tenantId)->whereKey($supplierId)->exists()) {
                    throw ReturnsError::on('supplier_id', 'rtv.supplier_unknown');
                }
                $warehouse = $this->stock->warehouse($tenantId, $targetWarehouseId, 'target_warehouse_id');
                $targetWarehouseId = $warehouse->id;
                $stockMovementId = $this->stock->receive(
                    (string) $product->id,
                    $quantity,
                    $warehouse,
                    $product,
                    __('returns_errors.grading.rtv_note', ['number' => $inspectionNumber])
                );
                $rtv = $this->supplierReturns->create($tenantId, [
                    'supplier_id' => $supplierId,
                    'warehouse_id' => $warehouse->id,
                    'reason_code' => 'defective',
                    'notes' => $inspectionNumber,
                    'lines' => [[
                        'product_id' => (string) $product->id,
                        'description' => (string) $product->name,
                        'quantity' => (float) $quantity,
                        'unit_cost' => (float) $originalCost,
                        'serial_number' => $data['serial_number'] ?? null,
                        'lot_number' => $data['lot_number'] ?? null,
                    ]],
                ], $userId);
                $supplierReturnId = $rtv->id;
            }

            $inspection = ReturnGradingInspection::create([
                'tenant_id' => $tenantId,
                'inspection_number' => $inspectionNumber,
                'customer_return_id' => $data['customer_return_id'] ?? null,
                'customer_return_line_id' => $data['customer_return_line_id'] ?? null,
                'rma_id' => $data['rma_id'] ?? null,
                'rma_line_id' => $data['rma_line_id'] ?? null,
                'product_id' => (string) $product->id,
                'serial_number' => $data['serial_number'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'quantity_inspected' => $quantity,
                'grade' => $grade,
                'disposition' => $disposition,
                'checklist' => $data['checklist'] ?? [],
                'photo_urls' => $photos,
                'original_unit_cost' => $originalCost,
                'devaluation_percent' => $devaluationPercent,
                'revalued_unit_cost' => $revaluedCost,
                'target_warehouse_id' => $targetWarehouseId,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_return_id' => $supplierReturnId,
                'refurbished_item_id' => $refurbishedItem?->id,
                'stock_movement_id' => $stockMovementId,
                'inspector_notes' => $data['inspector_notes'] ?? null,
                'inspected_by' => $userId,
                'inspected_at' => now(),
            ]);

            if ($refurbishedItem !== null) {
                $refurbishedItem->update(['return_grading_inspection_id' => $inspection->id]);
            }

            if (! empty($data['customer_return_line_id'])) {
                CustomerReturnLine::query()->whereKey($data['customer_return_line_id'])->update([
                    'quantity_inspected' => $quantity,
                    'grade' => $grade,
                    'disposition' => $disposition,
                    'stock_movement_id' => $stockMovementId,
                    'inspection_notes' => $data['inspector_notes'] ?? null,
                ]);
            }

            if (! empty($data['customer_return_id'])) {
                CustomerReturn::query()
                    ->whereKey($data['customer_return_id'])
                    ->whereIn('status', [CustomerReturn::StatusRequested, CustomerReturn::StatusApproved, CustomerReturn::StatusReceived])
                    ->update(['status' => CustomerReturn::StatusInspected, 'inspected_at' => now()]);
            }

            $this->ledger->record('returns.grading_inspection_recorded', $inspection, [
                'grade' => $grade,
                'disposition' => $disposition,
                'refurbished_item_id' => $refurbishedItem?->id,
            ]);

            return $inspection->refresh()->load(['product', 'customerReturn', 'refurbishedItem']);
        });
    }

    /**
     * Complete or update the lightweight reconditioning work order on a refurbished item.
     *
     * @param  array<string, mixed>  $data
     */
    public function completeWorkOrder(RefurbishedItem $item, array $data): RefurbishedItem
    {
        return DB::transaction(function () use ($item, $data): RefurbishedItem {
            $locked = RefurbishedItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $refurbCost = Decimal::round($data['refurbishment_cost'] ?? $locked->refurbishment_cost, 4);
            $newUnitCost = Decimal::round(
                Decimal::add(
                    Decimal::mul($locked->original_cost, Decimal::sub('1', Decimal::div($locked->devaluation_percent, '100'))),
                    $refurbCost
                ),
                4
            );

            $locked->update([
                'work_order_status' => $data['work_order_status'] ?? RefurbishedItem::WorkOrderCompleted,
                'work_order_steps' => $data['work_order_steps'] ?? $locked->work_order_steps,
                'refurbishment_cost' => $refurbCost,
                'new_unit_cost' => $newUnitCost,
                'suggested_sale_price' => Decimal::round($data['suggested_sale_price'] ?? $locked->suggested_sale_price, 4),
                'is_available' => (bool) ($data['is_available'] ?? true),
                'notes' => $data['notes'] ?? $locked->notes,
            ]);

            $this->ledger->record('returns.refurbished_work_order_completed', $locked);

            return $locked->refresh()->load(['originalProduct', 'refurbishedProduct']);
        });
    }

    private function resolveOutletWarehouse(string $tenantId, ?string $warehouseId): Warehouse
    {
        if ($warehouseId !== null) {
            $found = Warehouse::query()->where('tenant_id', $tenantId)->whereKey($warehouseId)->first();
            if ($found !== null) {
                return $found;
            }
        }

        $outlet = Warehouse::query()
            ->where('tenant_id', $tenantId)
            ->where('code', 'OUTLET')
            ->first();

        if ($outlet !== null) {
            return $outlet;
        }

        return $this->stock->warehouse($tenantId, null, 'target_warehouse_id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createRefurbishedBStock(
        string $tenantId,
        Product $originalProduct,
        string $grade,
        string $quantity,
        string $originalCost,
        string $devaluationPercent,
        string $revaluedCost,
        Warehouse $warehouse,
        array $data,
        ?string $userId,
    ): RefurbishedItem {
        $gradeTag = $grade === ReturnGradingInspection::GradeC ? 'C' : 'B';
        $bStockSkuBase = $originalProduct->sku.'-BSTOCK-'.$gradeTag;
        $bStockProduct = Product::query()->where('tenant_id', $tenantId)->where('sku', $bStockSkuBase)->first();

        $suggestedSalePrice = Decimal::round(
            $data['suggested_sale_price'] ?? Decimal::mul((string) ($originalProduct->sale_price ?? '0'), Decimal::sub('1', Decimal::div($devaluationPercent, '100'))),
            4
        );

        if ($bStockProduct === null) {
            $bStockProduct = Product::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'sku' => $bStockSkuBase,
                'name' => $originalProduct->name.' (B-Stock Grade '.$gradeTag.')',
                'description' => $originalProduct->description,
                'unit_measure' => $originalProduct->unit_measure ?? 'pz',
                'cost_price' => $revaluedCost,
                'sale_price' => $suggestedSalePrice,
                'tax_rate' => $originalProduct->tax_rate ?? 22,
                'track_inventory' => $originalProduct->track_inventory,
                'is_active' => true,
            ]);
        } else {
            $bStockProduct->update(['cost_price' => $revaluedCost]);
        }

        $refurbCost = Decimal::round($data['refurbishment_cost'] ?? '0', 4);
        $newUnitCost = Decimal::round(Decimal::add($revaluedCost, $refurbCost), 4);
        $uniqueSku = $bStockSkuBase.'-'.strtoupper(substr(Str::uuid()->toString(), 0, 6));

        $item = RefurbishedItem::create([
            'tenant_id' => $tenantId,
            'refurbished_sku' => $uniqueSku,
            'original_product_id' => (string) $originalProduct->id,
            'refurbished_product_id' => (string) $bStockProduct->id,
            'customer_return_id' => $data['customer_return_id'] ?? null,
            'rma_id' => $data['rma_id'] ?? null,
            'grade' => $grade,
            'serial_number' => $data['serial_number'] ?? null,
            'lot_number' => $data['lot_number'] ?? null,
            'quantity' => $quantity,
            'original_cost' => $originalCost,
            'devaluation_percent' => $devaluationPercent,
            'refurbishment_cost' => $refurbCost,
            'new_unit_cost' => $newUnitCost,
            'suggested_sale_price' => $suggestedSalePrice,
            'warehouse_id' => (string) $warehouse->id,
            'work_order_status' => $data['work_order_status'] ?? RefurbishedItem::WorkOrderCompleted,
            'work_order_steps' => $data['work_order_steps'] ?? [
                'cleaning' => true,
                'testing' => true,
                'repackaging' => true,
                'relabeling' => true,
            ],
            'is_available' => true,
            'notes' => $data['inspector_notes'] ?? null,
            'created_by' => $userId,
        ]);

        $movementId = $this->stock->receive(
            (string) $bStockProduct->id,
            $quantity,
            $warehouse,
            $item,
            __('returns_errors.grading.bstock_note', ['sku' => $uniqueSku])
        );

        $item->update(['stock_movement_id' => $movementId]);

        return $item;
    }
}
