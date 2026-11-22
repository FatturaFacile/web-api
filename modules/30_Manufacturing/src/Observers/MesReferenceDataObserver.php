<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Observers;

use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Events\MesExecutionStartedEvent;
use Modules\Manufacturing\Events\MesExecutionStoppedEvent;
use Modules\Manufacturing\Events\MesReasonCodeCatalogUpdatedEvent;
use Modules\Manufacturing\Events\MesWorkOrderAssignedToMachineEvent;
use Modules\Manufacturing\Events\MesWorkOrderClosedEvent;
use Modules\Manufacturing\Events\MesWorkOrderReleasedEvent;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgReasonCode;
use Modules\Manufacturing\Models\MfgReasonCodeLabel;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Services\MesSync\MaterialiseShiftInstancesAction;
use Modules\Manufacturing\Services\MesSync\MesChangeRecorder;

/**
 * Records, inside the transaction that writes them, the changes of the reference data of the MES Core: machines, the
 * operations of released production orders, reason codes and shop floor job cards. One observer registered on the five
 * models keeps the rules of what the MES Core sees in a single place.
 */
final class MesReferenceDataObserver
{
    /** Fields of an operation that change its representation for the MES Core. */
    private const array OperationFields = ['machine_center_id', 'work_center_id', 'ideal_cycle_time_ms', 'planned_run_minutes', 'planned_setup_minutes', 'sequence', 'is_subcontracted'];

    /** Fields of an order that change the representation of its operations. */
    private const array OrderFields = ['status', 'target_quantity', 'planned_start_date', 'planned_end_date', 'order_number'];

    /**
     * Work centres whose shift instances are already scheduled for materialisation after the current transaction.
     *
     * @var array<string, true>
     */
    private static array $pendingCalendars = [];

    public function __construct(private readonly MesChangeRecorder $recorder, private readonly MaterialiseShiftInstancesAction $materialise) {}

    /**
     * A machine was created or changed.
     */
    public function machineSaved(MfgMachineCenter $machine): void
    {
        $this->recorder->record((string) $machine->tenant_id, MesChangeRecorder::Machines, (string) $machine->id);
    }

    /**
     * A machine was deleted: the MES Core receives a tombstone.
     */
    public function machineDeleted(MfgMachineCenter $machine): void
    {
        $this->recorder->record((string) $machine->tenant_id, MesChangeRecorder::Machines, (string) $machine->id, MesChangeRecorder::Delete);
    }

    /**
     * A production order was saved: its operations are announced when it leaves `planned` or changes in a way that
     * alters them, and the release and closing are notified.
     */
    public function orderSaved(MfgProductionOrder $order): void
    {
        if ($order->status === 'planned' || ! ($order->wasRecentlyCreated || $order->wasChanged(self::OrderFields))) {
            return;
        }

        $seq = $this->recorder->recordOrderOperations($order);

        if ($seq === null || ! $order->wasChanged('status')) {
            return;
        }

        $tenantId = (string) $order->tenant_id;

        if ($order->getOriginal('status') === 'planned') {
            $this->recorder->notify(MesWorkOrderReleasedEvent::class, $tenantId, MesChangeRecorder::WorkOrders, (string) $order->id, $seq);
        }

        if (in_array($order->status, ['completed', 'closed', 'cancelled'], true)) {
            $this->recorder->notify(MesWorkOrderClosedEvent::class, $tenantId, MesChangeRecorder::WorkOrders, (string) $order->id, $seq);
        }
    }

    /**
     * An operation was created or changed.
     */
    public function operationSaved(MfgProductionOrderOperation $operation): void
    {
        if (! ($operation->wasRecentlyCreated || $operation->wasChanged(self::OperationFields))) {
            return;
        }

        $seq = $this->recorder->recordOperation($operation);

        if ($seq !== null && ! $operation->wasRecentlyCreated && $operation->wasChanged('machine_center_id')) {
            $this->recorder->notify(MesWorkOrderAssignedToMachineEvent::class, (string) $operation->tenant_id, MesChangeRecorder::WorkOrders, (string) $operation->id, $seq);
        }
    }

    /**
     * An operation was deleted: the MES Core receives a tombstone.
     */
    public function operationDeleted(MfgProductionOrderOperation $operation): void
    {
        $this->recorder->record((string) $operation->tenant_id, MesChangeRecorder::WorkOrders, (string) $operation->id, MesChangeRecorder::Delete);
    }

    /**
     * A reason code was saved, restored or deleted.
     */
    public function reasonCodeChanged(MfgReasonCode $reasonCode): void
    {
        $this->announceReasonCode((string) $reasonCode->tenant_id, (string) $reasonCode->id);
    }

    /**
     * A label of a reason code was saved or deleted.
     */
    public function reasonCodeLabelChanged(MfgReasonCodeLabel $label): void
    {
        $this->announceReasonCode((string) $label->tenant_id, (string) $label->reason_code_id);
    }

    /**
     * A shop floor job card was started or completed: the MES Core learns which order runs on the machine.
     */
    public function executionSaved(MfgShopFloorExecution $execution): void
    {
        $started = $execution->wasRecentlyCreated;
        $stopped = ! $started && $execution->wasChanged('status') && $execution->status === 'completed';

        if (! $started && ! $stopped) {
            return;
        }

        $operation = MfgProductionOrderOperation::query()->find($execution->production_order_operation_id);
        $seq = $operation !== null ? $this->recorder->recordOperation($operation) : null;

        if ($seq !== null) {
            $this->recorder->notify($started ? MesExecutionStartedEvent::class : MesExecutionStoppedEvent::class, (string) $execution->tenant_id, MesChangeRecorder::WorkOrders, (string) $execution->production_order_operation_id, $seq);
        }
    }

    /**
     * The weekly calendar or a dated exception of a work centre changed: its shift instances are materialised once, after
     * the transaction that rewrites the calendar commits (the calendar is replaced row by row).
     */
    public function calendarChanged(string $tenantId, string $workCenterId): void
    {
        $key = $tenantId.':'.$workCenterId;

        if (isset(self::$pendingCalendars[$key])) {
            return;
        }

        self::$pendingCalendars[$key] = true;

        DB::afterCommit(function () use ($key, $tenantId, $workCenterId): void {
            unset(self::$pendingCalendars[$key]);
            ($this->materialise)($tenantId, $workCenterId);
        });
    }

    private function announceReasonCode(string $tenantId, string $reasonCodeId): void
    {
        $seq = $this->recorder->record($tenantId, MesChangeRecorder::ReasonCodes, $reasonCodeId);
        $this->recorder->notify(MesReasonCodeCatalogUpdatedEvent::class, $tenantId, MesChangeRecorder::ReasonCodes, $reasonCodeId, $seq);
    }
}
