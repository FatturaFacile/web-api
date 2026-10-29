<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;

final class ManufacturingDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        $planningService = app(ProductionPlanningServiceInterface::class);
        $executionService = app(ProductionExecutionServiceInterface::class);
        $mrpService = app(MrpServiceInterface::class);

        foreach ($tenants as $tenant) {
            $pumpItem = MfgItem::where('tenant_id', $tenant->id)->where('item_code', 'FG-PUMP-HD500')->first();
            if (! $pumpItem || ! $pumpItem->default_bom_id) {
                continue;
            }

            // 1. Create a planned production order
            $order = MfgProductionOrder::where('tenant_id', $tenant->id)->where('mfg_item_id', $pumpItem->id)->first();
            if (! $order) {
                $order = $planningService->planOrder(new CreateProductionOrderDTO(
                    tenantId: $tenant->id,
                    mfgItemId: $pumpItem->id,
                    targetQuantity: 25.0,
                    plannedStartDate: now()->toDateString(),
                    plannedEndDate: now()->addDays(7)->toDateString(),
                    notes: 'Demo production batch for heavy duty industrial pumps'
                ));

                // 2. Release order and allocate lines
                $order = $executionService->releaseOrder($order->id);

                // 3. Start order
                $order = $executionService->startOrder($order->id);
            }

            // 4. Execute demo MRP Run
            $mrpService->executeMrpRun(new RunMrpDTO(
                tenantId: $tenant->id,
                horizonStartDate: now()->toDateString(),
                horizonEndDate: now()->addMonths(2)->toDateString(),
                runType: 'regenerative'
            ));
        }
    }
}
