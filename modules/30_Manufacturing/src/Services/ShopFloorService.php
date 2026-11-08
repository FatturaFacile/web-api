<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\DTOs\ConfirmOperationDTO;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgLaborTimeLog;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Support\Money;

/**
 * Shop floor job cards. The time of a completed job card (start to completion, less reported downtime) becomes the
 * run time of an operation confirmation, so labour, overhead and backflush follow the same rules as a manual
 * confirmation; the operator's labour is logged at the operation's labour rate.
 */
final readonly class ShopFloorService implements ShopFloorServiceInterface
{
    public function __construct(private ProductionExecutionService $execution) {}

    public function startExecution(MfgProductionOrderOperation $operation, ?string $operatorId, ?string $machineId, ?string $actorUserId): MfgShopFloorExecution
    {
        return DB::transaction(function () use ($operation, $operatorId, $machineId): MfgShopFloorExecution {
            $order = $this->execution->lockOpen(MfgProductionOrder::query()->findOrFail($operation->production_order_id));
            $op = MfgProductionOrderOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($op->isCompleted() || $op->is_subcontracted) {
                throw new ManufacturingOperationException("Operation {$op->sequence} is completed or subcontracted and takes no job cards.", 'production_order_operation_id');
            }

            $op->update(['status' => 'in_progress', 'started_at' => $op->started_at ?? now()]);
            if ($order->status === 'released') {
                $order->transitionTo('in_progress');
                $order->save();
            }

            return MfgShopFloorExecution::create([
                'tenant_id' => $order->tenant_id,
                'execution_number' => 'JC-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'production_order_id' => $order->id,
                'production_order_operation_id' => $op->id,
                'work_center_id' => $op->work_center_id,
                'operator_id' => $operatorId,
                'machine_id' => $machineId,
                'status' => 'active',
                'started_at' => now(),
            ]);
        });
    }

    public function completeExecution(MfgShopFloorExecution $execution, string $goodUnits, string $scrapUnits, ?string $scrapReason, string $setupMinutes, ?string $laborMinutes, bool $isFinal, ?string $postingDate, ?string $actorUserId): MfgShopFloorExecution
    {
        return DB::transaction(function () use ($execution, $goodUnits, $scrapUnits, $scrapReason, $setupMinutes, $laborMinutes, $isFinal, $postingDate, $actorUserId): MfgShopFloorExecution {
            $order = $this->execution->lockOpen(MfgProductionOrder::query()->findOrFail($execution->production_order_id));
            $locked = MfgShopFloorExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new ManufacturingOperationException("Job card [{$locked->execution_number}] is {$locked->status}.", 'status');
            }

            $completedAt = now();
            $elapsed = Money::div((string) (int) floor($locked->started_at->diffInSeconds($completedAt)), '60');
            $setup = Money::round($setupMinutes);
            $run = Money::round(Money::max('0', Money::sub(Money::sub($elapsed, $locked->downtime_minutes), $setup)));
            $op = MfgProductionOrderOperation::query()->whereKey($locked->production_order_operation_id)->lockForUpdate()->firstOrFail();

            $confirmation = $this->execution->recordConfirmation($order, $op, new ConfirmOperationDTO(
                setupMinutes: $setup,
                runMinutes: $run,
                laborMinutes: $laborMinutes,
                goodQuantity: $goodUnits,
                scrapQuantity: $scrapUnits,
                scrapReason: $scrapReason,
                isFinal: $isFinal,
                postingDate: $postingDate,
                downtimeMinutes: $locked->downtime_minutes,
                shopFloorExecutionId: (string) $locked->id,
            ), '0', null, $actorUserId);

            if ($locked->operator_id !== null) {
                MfgLaborTimeLog::create([
                    'tenant_id' => $locked->tenant_id,
                    'execution_id' => $locked->id,
                    'operator_id' => $locked->operator_id,
                    'start_time' => $locked->started_at,
                    'end_time' => $completedAt,
                    'duration_minutes' => $confirmation->labor_minutes,
                    'hourly_rate' => $op->labor_rate,
                    'labor_cost' => $confirmation->labor_cost,
                ]);
            }

            $locked->update([
                'status' => 'completed',
                'good_units' => Money::qty($goodUnits),
                'scrap_units' => Money::qty($scrapUnits),
                'completed_at' => $completedAt,
                'confirmation_id' => $confirmation->id,
            ]);

            return $locked->refresh();
        });
    }

    public function recordDowntime(MfgShopFloorExecution $execution, string $downtimeMinutes, string $reason): MfgShopFloorExecution
    {
        return DB::transaction(function () use ($execution, $downtimeMinutes, $reason): MfgShopFloorExecution {
            $locked = MfgShopFloorExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new ManufacturingOperationException("Job card [{$locked->execution_number}] is {$locked->status}.", 'status');
            }

            $locked->update([
                'downtime_minutes' => Money::round(Money::add($locked->downtime_minutes, $downtimeMinutes)),
                'downtime_reason' => trim(($locked->downtime_reason !== null && $locked->downtime_reason !== '' ? $locked->downtime_reason."\n" : '').Money::round($downtimeMinutes).' min: '.$reason),
            ]);

            return $locked->refresh();
        });
    }
}
