<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Models\MfgEdgeGateway;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * Issue #178 demo dataset on top of the manufacturing item and demo seeders: a NUM CNC milling machine linked to an
 * edge gateway, and one planned production order per strategy (MTO, MTS, ATO) of the finished good, so the cockpit,
 * the finite-capacity scheduler and the edge telemetry have data to work on. Idempotent per tenant.
 *
 * Run after ManufacturingItemSeeder and ManufacturingDemoDataSeeder; tenants without the finished good are skipped.
 * The gateway secret is generated, never fixed: read it from the API when registering a real gateway.
 */
final class ManufacturingDomainSeeder extends Seeder
{
    private const string FinishedGood = 'FG-PUMP-HD500';

    private const string Marker = 'Domain 07 demo';

    public function run(): void
    {
        $this->call([ManufacturingItemSeeder::class]);

        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->runWithTenant($tenant, fn () => $this->seedTenant($tenant));
        }
    }

    private function seedTenant(Tenant $tenant): void
    {
        $item = MfgItem::query()->where('tenant_id', $tenant->id)->where('item_code', self::FinishedGood)->first();
        $workCenter = MfgWorkCenter::query()->where('tenant_id', $tenant->id)->orderBy('work_center_code')->first();
        if (! $item instanceof MfgItem || ! $workCenter instanceof MfgWorkCenter) {
            return;
        }

        MfgMachineCenter::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'machine_code' => 'CNC-NUM-01'],
            ['name' => 'NUM CNC milling centre', 'work_center_id' => $workCenter->id, 'hourly_rate' => '95.0000', 'power_kw' => '22.00', 'status' => 'active', 'current_state' => 'IDLE', 'state_since' => now()],
        );

        MfgEdgeGateway::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Edge gateway - machining hall'],
            ['secret' => Str::random(64), 'is_active' => true],
        );

        if (app(BillOfMaterialsServiceInterface::class)->effectiveBomFor($item) === null) {
            return;
        }

        $planning = app(ProductionPlanningServiceInterface::class);
        foreach (['MTS' => ['40', 14], 'MTO' => ['12', 7], 'ATO' => ['6', 3]] as $strategy => [$quantity, $days]) {
            $exists = MfgProductionOrder::query()->where('tenant_id', $tenant->id)->where('production_strategy', $strategy)->where('notes', 'like', self::Marker.'%')->exists();
            if ($exists) {
                continue;
            }

            $order = $planning->planOrder(new CreateProductionOrderDTO(
                tenantId: (string) $tenant->id,
                mfgItemId: (string) $item->id,
                targetQuantity: $quantity,
                plannedStartDate: now()->toDateString(),
                plannedEndDate: now()->addDays($days)->toDateString(),
                notes: self::Marker.' - '.$strategy,
            ));
            $order->forceFill(['production_strategy' => $strategy])->save();
        }
    }
}
