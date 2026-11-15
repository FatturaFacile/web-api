<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\DTOs\InboundCostingDTO;
use Modules\Inventory\DTOs\InboundCostingResult;
use Modules\Inventory\DTOs\OutboundCostingDTO;
use Modules\Inventory\DTOs\OutboundCostingResult;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Events\PurchasePriceVarianceRecordedEvent;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\InventoryCostLayerConsumption;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Support\Decimal;
use Modules\Inventory\Support\JournalLegs;

/**
 * Valuation engine (FatturaFacile/FatturaFacile#144): FIFO cost layers, continuous weighted
 * average cost and standard cost with purchase price variance.
 *
 * Callers must run inside a DB transaction (DoubleEntryMovementService does).
 */
final class InventoryCostingService implements InventoryCostingServiceInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function resolveEffectiveMethod(StockItem $item): ValuationMethod
    {
        $itemMethod = ValuationMethod::fromStorage($item->valuation_method ?? null);
        if ($itemMethod !== null) {
            return $itemMethod;
        }

        $item->loadMissing('category');

        return ValuationMethod::fromStorage($item->category?->valuation_method) ?? ValuationMethod::WeightedAverage;
    }

    public function describeMethod(StockItem $item): array
    {
        $item->loadMissing('category');
        $itemMethod = ValuationMethod::fromStorage($item->valuation_method ?? null);
        $categoryMethod = ValuationMethod::fromStorage($item->category?->valuation_method);

        $source = match (true) {
            $itemMethod !== null => 'item',
            $categoryMethod !== null => 'category',
            default => 'default',
        };

        return [
            'category_method' => $categoryMethod?->value,
            'item_method' => $itemMethod?->value,
            'effective_method' => $this->resolveEffectiveMethod($item)->value,
            'source' => $source,
        ];
    }

    public function recordInbound(StockItem $item, InboundCostingDTO $dto): InboundCostingResult
    {
        /** @var StockItem $locked */
        $locked = StockItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
        $method = $this->resolveEffectiveMethod($locked);

        $quantity = Decimal::quantity($dto->quantity);
        $actualUnitCost = Decimal::unitCost($dto->unitCost);
        $standardCost = Decimal::unitCost((string) $locked->standard_cost);
        $usesStandard = $method === ValuationMethod::StandardCost && Decimal::isPositive($standardCost);

        // Continuous weighted average (recomputed at each inbound) — tracked for every method.
        $onHandBefore = Decimal::max('0', $dto->onHandBefore);
        $currentAverage = Decimal::unitCost((string) $locked->current_moving_average_cost);
        $newAverage = Decimal::isPositive($onHandBefore)
            ? Decimal::unitCost(Decimal::div(
                Decimal::add(Decimal::mul($onHandBefore, $currentAverage), Decimal::mul($quantity, $actualUnitCost)),
                Decimal::add($onHandBefore, $quantity)
            ))
            : $actualUnitCost;

        $locked->forceFill(['current_moving_average_cost' => $newAverage])->save();
        $item->setAttribute('current_moving_average_cost', $newAverage);

        $layerUnitCost = $usesStandard ? $standardCost : $actualUnitCost;

        $layer = InventoryCostLayer::create([
            'tenant_id' => $locked->tenant_id,
            'company_id' => $locked->company_id,
            'stock_item_id' => $locked->id,
            'warehouse_id' => $dto->warehouseId,
            'lot_id' => $dto->lotId,
            'source_movement_id' => $dto->movementId,
            'source_type' => $dto->sourceType,
            'receipt_id' => $dto->receiptId,
            'received_at' => Carbon::now(),
            'layer_sequence' => $this->nextLayerSequence((string) $locked->id),
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => $layerUnitCost,
            'landed_cost_unit' => '0',
            'currency' => $this->currency(),
        ]);

        $ppv = '0.00';
        if ($usesStandard && $dto->sourceType === 'receipt' && Decimal::comp($actualUnitCost, $standardCost) !== 0) {
            $ppv = Decimal::money(Decimal::mul($quantity, Decimal::sub($actualUnitCost, $standardCost)));
            if (! Decimal::isZero($ppv)) {
                $this->recordPurchasePriceVariance($locked, $dto, $quantity, $standardCost, $actualUnitCost, $ppv);
            }
        }

        return new InboundCostingResult(
            method: $method,
            layer: $layer,
            valuationUnitCost: $layerUnitCost,
            inventoryValue: Decimal::money(Decimal::mul($quantity, $layerUnitCost)),
            purchasePriceVariance: $ppv,
            movingAverageCost: $newAverage,
        );
    }

    public function consumeOutbound(StockItem $item, OutboundCostingDTO $dto): OutboundCostingResult
    {
        /** @var StockItem $locked */
        $locked = StockItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
        $method = $this->resolveEffectiveMethod($locked);
        $quantity = Decimal::quantity($dto->quantity);

        $this->ensureOpeningLayers($locked, $dto->onHandBefore);

        $layers = $this->openLayersQuery($locked->id)->lockForUpdate()->get();
        if ($dto->lotId !== null) {
            // Lot-tracked issues deplete the issued lot's layers first, then FIFO.
            $layers = $layers->sortBy(fn (InventoryCostLayer $layer): int => $layer->lot_id === $dto->lotId ? 0 : 1)->values();
        }

        $averageCost = Decimal::unitCost((string) $locked->current_moving_average_cost);
        $standardCost = Decimal::unitCost((string) $locked->standard_cost);

        $remaining = $quantity;
        /** @var list<array{layer: InventoryCostLayer, quantity: string, unit_cost: string}> $plan */
        $plan = [];
        foreach ($layers as $layer) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }

            $take = Decimal::quantity(Decimal::min($remaining, (string) $layer->remaining_quantity));
            if (! Decimal::isPositive($take)) {
                continue;
            }

            $unitCost = match ($method) {
                ValuationMethod::Fifo => $layer->effectiveUnitCost(),
                ValuationMethod::WeightedAverage => $averageCost,
                ValuationMethod::StandardCost => Decimal::isPositive($standardCost) ? $standardCost : $layer->effectiveUnitCost(),
            };

            $plan[] = ['layer' => $layer, 'quantity' => $take, 'unit_cost' => $unitCost];
            $remaining = Decimal::sub($remaining, $take);
        }

        if (Decimal::isPositive($remaining)) {
            throw ValidationException::withMessages([
                'quantity' => ["Issue quantity {$quantity} for item [{$locked->sku}] exceeds the remaining cost layers; negative stock is not allowed."],
            ]);
        }

        $cogs = match ($method) {
            ValuationMethod::Fifo => Decimal::money(array_reduce(
                $plan,
                static fn (string $carry, array $step): string => Decimal::add($carry, Decimal::mul($step['quantity'], $step['unit_cost'])),
                '0'
            )),
            ValuationMethod::WeightedAverage => Decimal::money(Decimal::mul($quantity, $averageCost)),
            ValuationMethod::StandardCost => Decimal::money(array_reduce(
                $plan,
                static fn (string $carry, array $step): string => Decimal::add($carry, Decimal::mul($step['quantity'], $step['unit_cost'])),
                '0'
            )),
        };

        $consumptions = [];
        $allocated = '0';
        $lastIndex = count($plan) - 1;
        foreach ($plan as $index => $step) {
            $amount = $index === $lastIndex
                ? Decimal::money(Decimal::sub($cogs, $allocated))
                : Decimal::money(Decimal::mul($step['quantity'], $step['unit_cost']));
            $allocated = Decimal::add($allocated, $amount);

            /** @var InventoryCostLayer $layer */
            $layer = $step['layer'];
            $layer->forceFill([
                'remaining_quantity' => Decimal::quantity(Decimal::sub((string) $layer->remaining_quantity, $step['quantity'])),
            ])->save();

            InventoryCostLayerConsumption::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'layer_id' => $layer->id,
                'stock_item_id' => $locked->id,
                'issue_movement_id' => $dto->movementId,
                'quantity' => $step['quantity'],
                'unit_cost' => $step['unit_cost'],
                'cogs_amount' => $amount,
                'consumed_at' => Carbon::now(),
            ]);

            $consumptions[] = [
                'layer_id' => (string) $layer->id,
                'quantity' => $step['quantity'],
                'unit_cost' => $step['unit_cost'],
                'cogs_amount' => $amount,
            ];
        }

        $unitCost = Decimal::isPositive($quantity) ? Decimal::unitCost(Decimal::div($cogs, $quantity)) : '0.0000';

        return new OutboundCostingResult(
            method: $method,
            unitCost: $unitCost,
            cogsAmount: $cogs,
            consumptions: $consumptions,
        );
    }

    public function valueOnHand(StockItem $item, ?ValuationMethod $method = null): string
    {
        $method ??= $this->resolveEffectiveMethod($item);
        $onHand = $this->onHand($item);

        return match ($method) {
            ValuationMethod::WeightedAverage => Decimal::money(Decimal::mul($onHand, (string) $item->current_moving_average_cost)),
            ValuationMethod::StandardCost => Decimal::money(Decimal::mul($onHand, (string) $item->standard_cost)),
            ValuationMethod::Fifo => $this->previewFifoCost($item, $onHand),
        };
    }

    public function previewFifoCost(StockItem $item, string $quantity): string
    {
        $remaining = Decimal::quantity($quantity);
        if (! Decimal::isPositive($remaining)) {
            return '0.00';
        }

        $value = '0';
        foreach ($this->openLayersQuery($item->id)->get() as $layer) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }
            $take = Decimal::min($remaining, (string) $layer->remaining_quantity);
            $value = Decimal::add($value, Decimal::mul($take, $layer->effectiveUnitCost()));
            $remaining = Decimal::sub($remaining, $take);
        }

        if (Decimal::isPositive($remaining)) {
            // Stock not covered by layers (pre-existing stock): fall back to moving average, then standard.
            $value = Decimal::add($value, Decimal::mul($remaining, $this->fallbackUnitCost($item)));
        }

        return Decimal::money($value);
    }

    public function ensureOpeningLayers(StockItem $item, ?string $onHand = null): ?InventoryCostLayer
    {
        $onHand = Decimal::quantity($onHand ?? $this->onHand($item));
        $covered = Decimal::of((string) InventoryCostLayer::query()
            ->where('stock_item_id', $item->id)
            ->where('remaining_quantity', '>', 0)
            ->sum('remaining_quantity'));

        $uncovered = Decimal::quantity(Decimal::sub($onHand, $covered));
        if (! Decimal::isPositive($uncovered)) {
            return null;
        }

        $oldest = InventoryCostLayer::query()->where('stock_item_id', $item->id)->min('received_at');
        $receivedAt = $oldest !== null ? Carbon::parse((string) $oldest)->subSecond() : Carbon::now()->subSecond();

        return InventoryCostLayer::create([
            'tenant_id' => $item->tenant_id,
            'company_id' => $item->company_id,
            'stock_item_id' => $item->id,
            'warehouse_id' => null,
            'lot_id' => null,
            'source_movement_id' => null,
            'source_type' => 'opening_balance',
            'receipt_id' => null,
            'received_at' => $receivedAt,
            'layer_sequence' => 0,
            'original_quantity' => $uncovered,
            'remaining_quantity' => $uncovered,
            'unit_cost' => $this->fallbackUnitCost($item),
            'landed_cost_unit' => '0',
            'currency' => $this->currency(),
        ]);
    }

    public function accountOverrides(StockItem $item): array
    {
        $item->loadMissing('category');
        $category = $item->category;

        $value = static fn (string $column): ?string => $category !== null && ! empty($category->getAttribute($column))
            ? (string) $category->getAttribute($column)
            : null;

        return [
            JournalLegs::ROLE_INVENTORY => $value('inventory_account_id'),
            JournalLegs::ROLE_COGS => $value('cogs_account_id'),
            JournalLegs::ROLE_PPV => $value('ppv_account_id'),
            JournalLegs::ROLE_LANDED_COST_CLEARING => $value('landed_cost_clearing_account_id'),
        ];
    }

    /**
     * @return Builder<InventoryCostLayer>
     */
    private function openLayersQuery(string $itemId): Builder
    {
        return InventoryCostLayer::query()
            ->where('stock_item_id', $itemId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('received_at')
            ->orderBy('layer_sequence');
    }

    private function nextLayerSequence(string $itemId): int
    {
        // The stock item row is locked by the caller, so max + 1 is race-free per item.
        return ((int) InventoryCostLayer::query()->where('stock_item_id', $itemId)->max('layer_sequence')) + 1;
    }

    private function onHand(StockItem $item): string
    {
        return Decimal::quantity((string) StockQuant::query()->where('stock_item_id', $item->id)->sum('quantity_on_hand'));
    }

    private function fallbackUnitCost(StockItem $item): string
    {
        $average = Decimal::unitCost((string) $item->current_moving_average_cost);

        return Decimal::isPositive($average) ? $average : Decimal::unitCost((string) $item->standard_cost);
    }

    private function currency(): string
    {
        $currency = $this->tenantContext->getTenant()?->default_currency;

        return is_string($currency) && strlen($currency) === 3 ? strtoupper($currency) : 'EUR';
    }

    private function recordPurchasePriceVariance(
        StockItem $item,
        InboundCostingDTO $dto,
        string $quantity,
        string $standardCost,
        string $actualUnitCost,
        string $ppv
    ): void {
        $accounts = $this->accountOverrides($item);
        $legs = (new JournalLegs)
            ->debit(JournalLegs::ROLE_PPV, $accounts[JournalLegs::ROLE_PPV], $ppv, "Purchase price variance {$item->sku}")
            ->credit(JournalLegs::ROLE_INVENTORY, $accounts[JournalLegs::ROLE_INVENTORY], $ppv, "Inventory to standard cost {$item->sku}")
            ->toArray();

        event(new PurchasePriceVarianceRecordedEvent(
            movementId: $dto->movementId,
            stockItemId: (string) $item->id,
            quantity: $quantity,
            standardUnitCost: $standardCost,
            actualUnitCost: $actualUnitCost,
            varianceAmount: $ppv,
            postingDate: Carbon::now()->toDateString(),
            legs: $legs,
            tenantId: (string) $item->tenant_id,
            companyId: (string) ($item->company_id ?? $item->tenant_id),
            eventId: (string) Str::uuid7(),
        ));
    }
}
