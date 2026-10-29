<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Modules\Manufacturing\Contracts\ManufacturingForecastingServiceInterface;
use Modules\Manufacturing\Events\InventoryForecastEvent;
use Modules\Manufacturing\Events\MaterialPurchaseForecastEvent;
use Modules\Manufacturing\Events\ProductionCashForecastEvent;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpShortage;
use Modules\Manufacturing\Models\MfgProductionOrder;

final readonly class ManufacturingForecastingService implements ManufacturingForecastingServiceInterface
{
    public function __construct(
        private EventBusInterface $eventBus
    ) {}

    public function generateCashAndMaterialForecast(string $tenantId, int $months = 6): array
    {
        $forecast = [];
        $baseDate = now()->startOfMonth();

        $totalMaterialPurchase = 0.0;
        $purchaseReqs = [];
        $inventoryProjections = [];

        for ($i = 0; $i < $months; $i++) {
            $monthDate = $baseDate->copy()->addMonths($i);
            $monthKey = $monthDate->format('Y-m');

            // Production cost estimate for orders due in this month
            $startOfMonth = $monthDate->copy()->startOfMonth()->toDateString();
            $endOfMonth = $monthDate->copy()->endOfMonth()->toDateString();

            $prodCost = (float) MfgProductionOrder::where('tenant_id', $tenantId)
                ->whereBetween('planned_end_date', [$startOfMonth, $endOfMonth])
                ->sum('standard_cost_total');

            // Material purchase requirements from shortages
            $materialPurchase = (float) MfgMrpShortage::where('tenant_id', $tenantId)
                ->whereBetween('required_date', [$startOfMonth, $endOfMonth])
                ->where('recommended_action', 'purchase_order')
                ->sum('net_shortage') * 15.00; // estimated unit rate

            $plannedMakeUnits = (float) MfgMrpPlannedOrder::where('tenant_id', $tenantId)
                ->where('order_type', 'make')
                ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
                ->sum('quantity');

            $inventoryInflow = round($plannedMakeUnits * 45.0, 4);

            $forecast[] = [
                'month' => $monthKey,
                'projected_production_cost' => round($prodCost, 2),
                'projected_material_purchase_cost' => round($materialPurchase, 2),
                'projected_inventory_inflow' => round($inventoryInflow, 2),
            ];

            $totalMaterialPurchase += $materialPurchase;
            $purchaseReqs[] = [
                'month' => $monthKey,
                'estimated_cost' => round($materialPurchase, 2),
            ];
            $inventoryProjections[] = [
                'month' => $monthKey,
                'estimated_inflow' => round($inventoryInflow, 2),
            ];
        }

        $this->eventBus->dispatch(new ProductionCashForecastEvent(
            monthlyForecast: $forecast,
            currency: 'EUR',
            tenantId: $tenantId
        ));

        $this->eventBus->dispatch(new MaterialPurchaseForecastEvent(
            purchaseRequirements: $purchaseReqs,
            totalEstimatedOutflow: round($totalMaterialPurchase, 2),
            currency: 'EUR',
            tenantId: $tenantId
        ));

        $this->eventBus->dispatch(new InventoryForecastEvent(
            projectedInventoryValuation: $inventoryProjections,
            currency: 'EUR',
            tenantId: $tenantId
        ));

        return $forecast;
    }
}
