<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgReasonCode;
use Modules\Manufacturing\Models\MfgShiftInstance;
use Modules\Manufacturing\Services\MesSync\MaterialiseShiftInstancesAction;
use Modules\Manufacturing\Services\MesSync\MesChangeRecorder;

/**
 * Bootstraps the change log for the reference data that existed before the MES integration: the log only holds changes
 * made after it was introduced, so a new MES replica would otherwise miss every unchanged entity. Materialises the shift
 * instances first, then records one change per machine, exposed order operation, reason code and shift instance.
 */
final class BackfillMesSyncCommand extends Command
{
    protected $signature = 'mfg:mes-backfill {--tenant= : Limit to one tenant id}';

    protected $description = 'Record the existing manufacturing reference data in the MES change log (issue #184)';

    /**
     * Backfills the log of every tenant, or of the given one.
     */
    public function handle(MesChangeRecorder $recorder, MaterialiseShiftInstancesAction $materialise, TenantContext $tenantContext): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant') !== null, fn ($query) => $query->whereKey((string) $this->option('tenant')))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->id;
            $materialise($tenantId);

            $count = $tenantContext->runWithTenant($tenant, function () use ($recorder, $tenantId): int {
                return DB::transaction(function () use ($recorder, $tenantId): int {
                    $count = 0;

                    foreach (MfgMachineCenter::query()->get() as $machine) {
                        $recorder->record($tenantId, MesChangeRecorder::Machines, (string) $machine->id);
                        $count++;
                    }

                    foreach (MfgProductionOrder::query()->where('status', '!=', 'planned')->get() as $order) {
                        foreach (MfgProductionOrderOperation::query()->where('production_order_id', $order->id)->get() as $operation) {
                            $count += $recorder->recordOperation($operation, $order) !== null ? 1 : 0;
                        }
                    }

                    foreach (MfgReasonCode::query()->get() as $reasonCode) {
                        $recorder->record($tenantId, MesChangeRecorder::ReasonCodes, (string) $reasonCode->id);
                        $count++;
                    }

                    foreach (MfgShiftInstance::query()->get() as $shift) {
                        $recorder->record($tenantId, MesChangeRecorder::ShiftCalendars, (string) $shift->id);
                        $count++;
                    }

                    return $count;
                });
            });

            $this->info("Tenant {$tenantId}: {$count} change(s) recorded.");
        }

        return self::SUCCESS;
    }
}
