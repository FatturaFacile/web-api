<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;

final readonly class RoutingService implements RoutingServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createRouting(CreateRoutingDTO $dto): MfgRouting
    {
        return DB::transaction(function () use ($dto): MfgRouting {
            $routingNumber = 'RTG-'.strtoupper((string) Str::random(8));

            $routing = MfgRouting::create([
                'tenant_id' => $dto->tenantId,
                'routing_number' => $routingNumber,
                'mfg_item_id' => $dto->mfgItemId,
                'name' => $dto->name,
                'revision' => $dto->revision,
                'status' => 'active',
                'is_default' => $dto->isDefault,
            ]);

            $seq = 10;
            foreach ($dto->operations as $op) {
                MfgOperation::create([
                    'tenant_id' => $dto->tenantId,
                    'routing_id' => $routing->id,
                    'sequence' => $op['sequence'] ?? $seq,
                    'operation_code' => $op['operation_code'],
                    'name' => $op['name'],
                    'work_center_id' => $op['work_center_id'],
                    'machine_center_id' => $op['machine_center_id'] ?? null,
                    'setup_time_minutes' => $op['setup_time_minutes'] ?? 0.0,
                    'run_time_minutes_per_unit' => $op['run_time_minutes_per_unit'] ?? 0.0,
                    'labor_time_minutes_per_unit' => $op['labor_time_minutes_per_unit'] ?? 0.0,
                    'queue_time_minutes' => $op['queue_time_minutes'] ?? 0.0,
                    'wait_time_minutes' => $op['wait_time_minutes'] ?? 0.0,
                    'teardown_time_minutes' => $op['teardown_time_minutes'] ?? 0.0,
                    'standard_labor_rate' => $op['standard_labor_rate'] ?? 25.0000,
                    'standard_machine_rate' => $op['standard_machine_rate'] ?? 45.0000,
                ]);
                $seq += 10;
            }

            // Link as default routing if item has none
            $item = MfgItem::where('id', $dto->mfgItemId)->where('tenant_id', $dto->tenantId)->first();
            if ($item && ($item->default_routing_id === null || $dto->isDefault)) {
                $item->update(['default_routing_id' => $routing->id]);
            }

            $this->auditLedger->record(
                'mfg.routing.created',
                $routing,
                null,
                ['routing_number' => $routing->routing_number],
                '30_Manufacturing'
            );

            return $routing->load('operations.workCenter');
        });
    }

    public function calculateTotalStandardTimes(string $routingId, float $units): array
    {
        $routing = MfgRouting::with('operations')->findOrFail($routingId);
        $totalSetup = 0.0;
        $totalRun = 0.0;
        $totalLabor = 0.0;
        $totalQueueWait = 0.0;

        foreach ($routing->operations as $op) {
            $totalSetup += (float) $op->setup_time_minutes;
            $totalRun += (float) $op->run_time_minutes_per_unit * $units;
            $totalLabor += (float) $op->labor_time_minutes_per_unit * $units;
            $totalQueueWait += (float) $op->queue_time_minutes + (float) $op->wait_time_minutes + (float) $op->teardown_time_minutes;
        }

        return [
            'total_setup_minutes' => round($totalSetup, 2),
            'total_run_minutes' => round($totalRun, 2),
            'total_labor_minutes' => round($totalLabor, 2),
            'total_queue_wait_minutes' => round($totalQueueWait, 2),
            'total_cycle_minutes' => round($totalSetup + $totalRun + $totalQueueWait, 2),
        ];
    }

    public function calculateStandardOperationsCost(string $routingId, float $units): array
    {
        $routing = MfgRouting::with(['operations.workCenter'])->findOrFail($routingId);
        $totalLaborCost = 0.0;
        $totalMachineCost = 0.0;
        $totalOverheadCost = 0.0;

        foreach ($routing->operations as $op) {
            $laborHours = ((float) $op->labor_time_minutes_per_unit * $units) / 60.0;
            $machineHours = (((float) $op->setup_time_minutes) + ((float) $op->run_time_minutes_per_unit * $units)) / 60.0;

            $laborRate = (float) $op->standard_labor_rate > 0 ? (float) $op->standard_labor_rate : ((float) $op->workCenter?->hourly_cost_rate ?? 25.0);
            $machineRate = (float) $op->standard_machine_rate > 0 ? (float) $op->standard_machine_rate : 40.0;
            $overheadRate = (float) $op->workCenter?->hourly_overhead_rate ?? 15.0;

            $totalLaborCost += $laborHours * $laborRate;
            $totalMachineCost += $machineHours * $machineRate;
            $totalOverheadCost += $machineHours * $overheadRate;
        }

        return [
            'labor_cost' => round($totalLaborCost, 4),
            'machine_cost' => round($totalMachineCost, 4),
            'overhead_cost' => round($totalOverheadCost, 4),
            'total_cost' => round($totalLaborCost + $totalMachineCost + $totalOverheadCost, 4),
        ];
    }
}
