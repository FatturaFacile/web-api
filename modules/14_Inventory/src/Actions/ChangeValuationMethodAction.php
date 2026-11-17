<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\DTOs\ItemValuationUpdateDTO;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Services\InventoryRevaluationService;
use Modules\Inventory\Support\Decimal;

final class ChangeValuationMethodAction
{
    public function __construct(
        private readonly InventoryCostingServiceInterface $costing,
        private readonly InventoryRevaluationService $revaluationService
    ) {}

    /**
     * Updates the item valuation settings. When the effective method or (for standard cost) the
     * standard cost changes with stock on hand, the stock is revalued (inventory_revaluation + journal).
     *
     * @return array{item: StockItem, revaluation: InventoryRevaluation|null}
     */
    public function updateItem(ItemValuationUpdateDTO $dto): array
    {
        return DB::transaction(function () use ($dto): array {
            /** @var StockItem $item */
            $item = StockItem::query()->whereKey($dto->itemId)->lockForUpdate()->firstOrFail();
            $item->load('category');

            // Pre-existing stock without layers is backfilled first so FIFO values are comparable.
            $this->costing->ensureOpeningLayers($item);

            $oldMethod = $this->costing->resolveEffectiveMethod($item);
            $oldStandard = Decimal::unitCost((string) $item->standard_cost);
            $oldValue = $this->costing->valueOnHand($item, $oldMethod);

            $changes = [];
            if ($dto->methodProvided) {
                $changes['valuation_method'] = $dto->valuationMethod?->value;
            }
            if ($dto->standardCost !== null) {
                $changes['standard_cost'] = Decimal::unitCost($dto->standardCost);
            }
            if ($dto->weightProvided) {
                $changes['weight_kg'] = $dto->weightKg;
            }
            if ($dto->volumeProvided) {
                $changes['volume_m3'] = $dto->volumeM3;
            }

            $item->forceFill($changes)->save();
            $item->refresh()->load('category');

            $newMethod = $this->costing->resolveEffectiveMethod($item);
            $newStandard = Decimal::unitCost((string) $item->standard_cost);

            $valuationChanged = $oldMethod !== $newMethod
                || ($newMethod === ValuationMethod::StandardCost && Decimal::comp($oldStandard, $newStandard) !== 0);

            $revaluation = null;
            if ($dto->revalueOnChange && $valuationChanged) {
                $newValue = $this->costing->valueOnHand($item, $newMethod);
                $revaluation = $this->revaluationService->revalueMethodChange($item, $oldMethod, $oldValue, $newMethod, $newValue, $dto->userId);
            }

            return ['item' => $item, 'revaluation' => $revaluation];
        });
    }

    /**
     * Updates the category default method and GL account overrides. Items inheriting the method
     * are not revalued here (use the item endpoint with `revalue_on_change`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateCategory(string $categoryId, array $attributes): StockItemCategory
    {
        return DB::transaction(function () use ($categoryId, $attributes): StockItemCategory {
            /** @var StockItemCategory $category */
            $category = StockItemCategory::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();

            $changes = [];
            if (array_key_exists('valuation_method', $attributes)) {
                $method = ValuationMethod::fromStorage(is_string($attributes['valuation_method']) ? $attributes['valuation_method'] : null);
                $changes['valuation_method'] = ($method ?? ValuationMethod::WeightedAverage)->value;
            }
            foreach (['inventory_account_id', 'cogs_account_id', 'ppv_account_id', 'landed_cost_clearing_account_id'] as $column) {
                if (array_key_exists($column, $attributes)) {
                    $changes[$column] = $attributes[$column];
                }
            }

            $category->forceFill($changes)->save();

            return $category->refresh();
        });
    }
}
