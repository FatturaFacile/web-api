<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Enterprise\EventBus\Services\EventDispatcher;
use Modules\Manufacturing\Enums\MachineMode;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Mes\Commands\TakeOverPhaseCommand;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Services\ProductionExecutionService;

/**
 * Takes a phase over (Italian: presa in carico): creates it on the first take-over of an operation, reassigns it
 * before work starts and hands it over while suspended.
 */
final class TakeOverPhaseHandler extends PhaseCommandHandler
{
    public function __construct(EventDispatcher $dispatcher, private readonly ProductionExecutionService $execution)
    {
        parent::__construct($dispatcher);
    }

    /**
     * Handles the command and returns the phase.
     */
    public function handle(TakeOverPhaseCommand $command): MfgMesPhase
    {
        return $this->execute($command, function () use ($command): MfgMesPhase {
            $operation = MfgProductionOrderOperation::query()->where('tenant_id', $command->tenantId)->whereKey($command->operationId)->lockForUpdate()->firstOrFail();
            $phase = MfgMesPhase::query()->where('tenant_id', $command->tenantId)->where('production_order_operation_id', $operation->id)->lockForUpdate()->first();

            if ($phase instanceof MfgMesPhase) {
                if ($command->expectedVersion !== null && $command->expectedVersion !== $phase->version) {
                    throw new ManufacturingOperationException("The phase changed meanwhile (version {$phase->version}, expected {$command->expectedVersion}): reload it.", 'version');
                }
                $this->assertAllowed(PhaseAction::TakeOver, $phase);
                $this->effectiveAt($command, $phase);
                $phase->operator_id = $command->operatorId;
                $phase->machine_id = $command->machineId ?? $phase->machine_id;
                $this->save($phase);
                $this->publishTransition($phase, PhaseAction::TakeOver);

                return $phase;
            }

            return $this->createPhase($command, $operation);
        });
    }

    /**
     * Creates the phase of an operation nobody has taken over and starts the order if it was only released.
     */
    private function createPhase(TakeOverPhaseCommand $command, MfgProductionOrderOperation $operation): MfgMesPhase
    {
        $order = $this->execution->lockOpen(MfgProductionOrder::query()->where('tenant_id', $command->tenantId)->findOrFail($operation->production_order_id));
        if ($operation->isCompleted() || $operation->is_subcontracted) {
            throw new ManufacturingOperationException("Operation {$operation->sequence} is completed or subcontracted and takes no phase.", 'production_order_operation_id');
        }
        $jobCard = MfgShopFloorExecution::query()->where('tenant_id', $command->tenantId)->where('production_order_operation_id', $operation->id)->where('status', 'active')->exists();
        if ($jobCard) {
            throw new ManufacturingOperationException("Operation {$operation->sequence} has an active job card: complete it first.", 'production_order_operation_id');
        }

        $at = $this->effectiveAt($command, null);
        $operation->update(['status' => 'in_progress', 'started_at' => $operation->started_at ?? $at]);
        if ($order->status === 'released') {
            $order->transitionTo('in_progress');
            $order->save();
        }

        $phase = MfgMesPhase::create([
            'tenant_id' => $command->tenantId,
            'production_order_id' => $order->id,
            'production_order_operation_id' => $operation->id,
            'work_center_id' => $operation->work_center_id,
            'machine_id' => $command->machineId ?? $operation->machine_center_id,
            'operator_id' => $command->operatorId,
            'status' => PhaseStatus::Assigned,
            'machine_mode' => MachineMode::Attended,
            'version' => 1,
            'taken_over_at' => $at,
        ]);
        $this->publishTransition($phase, PhaseAction::TakeOver);

        return $phase;
    }
}
