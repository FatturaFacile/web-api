<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

interface ManufacturingForecastingServiceInterface
{
    /**
     * @return array<int, array{
     *     month: string,
     *     projected_production_cost: float,
     *     projected_material_purchase_cost: float,
     *     projected_inventory_inflow: float
     * }>
     */
    public function generateCashAndMaterialForecast(string $tenantId, int $months = 6): array;
}
