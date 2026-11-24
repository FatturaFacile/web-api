<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;

/**
 * Demo data of manufacturing: a planned production order for an item with a released BOM, then an MRP run. Orders are
 * not released here, because release reserves stock that only the inventory of a real company holds.
 */
final class ManufacturingDemoDataSeeder extends Seeder
{
    /**
     * Seeds every tenant once (idempotent on the planned order of the item).
     */
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        $planning = app(ProductionPlanningServiceInterface::class);
        $boms = app(BillOfMaterialsServiceInterface::class);
        $mrp = app(MrpServiceInterface::class);

        foreach ($tenants as $tenant) {
            foreach (['FG-PUMP-HD500', 'TEST-FG-ASSEMBLY'] as $code) {
                $item = MfgItem::query()->where('tenant_id', $tenant->id)->where('item_code', $code)->first();
                if (! $item instanceof MfgItem || $boms->effectiveBomFor($item) === null) {
                    continue;
                }

                if (! MfgProductionOrder::query()->where('tenant_id', $tenant->id)->where('mfg_item_id', $item->id)->exists()) {
                    $planning->planOrder(new CreateProductionOrderDTO(
                        tenantId: (string) $tenant->id,
                        mfgItemId: (string) $item->id,
                        targetQuantity: '25',
                        plannedStartDate: now()->toDateString(),
                        plannedEndDate: now()->addDays(7)->toDateString(),
                        notes: 'Demo production batch'
                    ));
                }
            }

            $mrp->executeMrpRun(new RunMrpDTO(
                tenantId: (string) $tenant->id,
                horizonStartDate: now()->toDateString(),
                horizonEndDate: now()->addMonths(2)->toDateString(),
                runType: 'regenerative'
            ));
        }
    }
}
