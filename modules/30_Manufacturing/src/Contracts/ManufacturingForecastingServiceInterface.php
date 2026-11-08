<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

/**
 * Monthly production projection for Treasury.
 */
interface ManufacturingForecastingServiceInterface
{
    /**
     * Projects production cost, material purchases and inventory inflow per month at standard cost, and publishes
     * them as forecast events.
     *
     * @return list<array{month: string, projected_production_cost: float, projected_material_purchase_cost: float, projected_inventory_inflow: float}>
     */
    public function generateCashAndMaterialForecast(string $tenantId, int $months = 6): array;
}
