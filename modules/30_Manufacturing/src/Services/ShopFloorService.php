<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\DTOs\RecordShopFloorExecutionDTO;
use Modules\Manufacturing\Models\MfgLaborTimeLog;
use Modules\Manufacturing\Models\MfgMachineLog;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

final readonly class ShopFloorService implements ShopFloorServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function recordExecution(RecordShopFloorExecutionDTO $dto): MfgShopFloorExecution
    {
        return DB::transaction(function () use ($dto): MfgShopFloorExecution {
            $executionNumber = 'JBC-'.strtoupper((string) Str::random(8));

            $execution = MfgShopFloorExecution::create([
                'tenant_id' => $dto->tenantId,
                'execution_number' => $executionNumber,
                'production_order_id' => $dto->productionOrderId,
                'production_order_operation_id' => $dto->productionOrderOperationId,
                'work_center_id' => $dto->workCenterId,
                'operator_id' => $dto->operatorId,
                'machine_id' => $dto->machineId,
                'status' => 'active',
                'good_units' => $dto->goodUnits,
                'scrap_units' => $dto->scrapUnits,
                'started_at' => now(),
                'downtime_reason' => $dto->downtimeReason,
            ]);

            // Create labor time logs if provided
            foreach ($dto->laborLogs as $log) {
                $cost = round(((float) $log['duration_minutes'] / 60.0) * (float) $log['hourly_rate'], 4);
                MfgLaborTimeLog::create([
                    'tenant_id' => $dto->tenantId,
                    'execution_id' => $execution->id,
                    'operator_id' => $log['operator_id'],
                    'start_time' => $log['start_time'],
                    'end_time' => $log['end_time'],
                    'duration_minutes' => $log['duration_minutes'],
                    'hourly_rate' => $log['hourly_rate'],
                    'labor_cost' => $cost,
                ]);
            }

            // Create machine logs if provided
            foreach ($dto->machineLogs as $mLog) {
                $cost = round(((float) $mLog['run_minutes'] / 60.0) * (float) $mLog['hourly_rate'], 4);
                MfgMachineLog::create([
                    'tenant_id' => $dto->tenantId,
                    'execution_id' => $execution->id,
                    'machine_id' => $mLog['machine_id'],
                    'start_time' => $mLog['start_time'],
                    'end_time' => $mLog['end_time'],
                    'run_minutes' => $mLog['run_minutes'],
                    'downtime_minutes' => $mLog['downtime_minutes'],
                    'hourly_rate' => $mLog['hourly_rate'],
                    'machine_cost' => $cost,
                ]);
            }

            // Update operation status to in_progress
            MfgProductionOrderOperation::where('id', $dto->productionOrderOperationId)->update([
                'status' => 'in_progress',
            ]);

            $this->auditLedger->record(
                'mfg.shop_floor.execution_started',
                $execution,
                null,
                ['execution_number' => $execution->execution_number],
                '30_Manufacturing'
            );

            return $execution->load(['laborLogs', 'machineLogs']);
        });
    }

    public function completeExecution(string $executionId, float $goodUnits, float $scrapUnits): MfgShopFloorExecution
    {
        return DB::transaction(function () use ($executionId, $goodUnits, $scrapUnits): MfgShopFloorExecution {
            $execution = MfgShopFloorExecution::findOrFail($executionId);
            $execution->update([
                'status' => 'completed',
                'good_units' => $goodUnits,
                'scrap_units' => $scrapUnits,
                'completed_at' => now(),
            ]);

            $operation = MfgProductionOrderOperation::find($execution->production_order_operation_id);
            if ($operation) {
                $operation->completed_quantity = (float) $operation->completed_quantity + $goodUnits;
                $operation->scrapped_quantity = (float) $operation->scrapped_quantity + $scrapUnits;
                $operation->status = 'completed';
                $operation->save();
            }

            $this->auditLedger->record(
                'mfg.shop_floor.execution_completed',
                $execution,
                null,
                ['good_units' => $goodUnits, 'scrap_units' => $scrapUnits],
                '30_Manufacturing'
            );

            return $execution;
        });
    }

    public function recordDowntime(string $executionId, float $downtimeMinutes, string $reason): MfgShopFloorExecution
    {
        $execution = MfgShopFloorExecution::findOrFail($executionId);
        $execution->update([
            'status' => 'paused',
            'downtime_reason' => $reason,
        ]);

        if ($execution->machine_id) {
            MfgMachineLog::create([
                'tenant_id' => $execution->tenant_id,
                'execution_id' => $execution->id,
                'machine_id' => $execution->machine_id,
                'start_time' => now()->subMinutes((int) $downtimeMinutes),
                'end_time' => now(),
                'run_minutes' => 0.0,
                'downtime_minutes' => $downtimeMinutes,
                'hourly_rate' => 0.0,
                'machine_cost' => 0.0,
            ]);
        }

        $this->auditLedger->record(
            'mfg.shop_floor.downtime_recorded',
            $execution,
            null,
            ['downtime_minutes' => $downtimeMinutes, 'reason' => $reason],
            '30_Manufacturing'
        );

        return $execution;
    }
}
