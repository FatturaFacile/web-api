<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesSync;

use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgReasonCode;
use Modules\Manufacturing\Models\MfgShiftInstance;
use Modules\Manufacturing\Support\IdealCycleTime;
use Modules\Manufacturing\Support\Money;

/**
 * The representation of the reference data the MES Core pulls. It is built from the state at read time, not from the
 * change log: an entity that no longer exists or is not exposed (a planned order, a deleted machine) is a tombstone.
 */
final class MesSyncPresenter
{
    /** The MES status of an ERP order status; `planned` is not exposed and the ERP has no `paused`. */
    private const array WorkOrderStatus = [
        'released' => 'Released',
        'in_progress' => 'InProgress',
        'completed' => 'Completed',
        'closed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /**
     * The data of the given entities of a resource, keyed by id; null stands for an entity that is gone or not exposed.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>|null>
     */
    public function present(string $resource, string $tenantId, array $ids): array
    {
        $found = match ($resource) {
            MesChangeRecorder::Machines => $this->machines($tenantId, $ids),
            MesChangeRecorder::WorkOrders => $this->workOrders($tenantId, $ids),
            MesChangeRecorder::ShiftCalendars => $this->shiftInstances($tenantId, $ids),
            MesChangeRecorder::ReasonCodes => $this->reasonCodes($tenantId, $ids),
            default => [],
        };

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = $found[$id] ?? null;
        }

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function machines(string $tenantId, array $ids): array
    {
        $data = [];

        foreach (MfgMachineCenter::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->get() as $machine) {
            $data[(string) $machine->id] = [
                'machine_id' => (string) $machine->id,
                'machine_code' => $machine->machine_code,
                'name' => $machine->name,
                'work_center_id' => (string) $machine->work_center_id,
                'asset_id' => $machine->asset_id,
                'power_kw' => $machine->power_kw,
                'status' => $machine->status,
            ];
        }

        return $data;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function workOrders(string $tenantId, array $ids): array
    {
        $operations = MfgProductionOrderOperation::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->with('productionOrder')->get();
        $itemCodes = MfgItem::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $operations->map(static fn (MfgProductionOrderOperation $operation): ?string => $operation->productionOrder?->mfg_item_id)->filter()->unique()->values()->all())
            ->pluck('item_code', 'id');

        $data = [];

        foreach ($operations as $operation) {
            $order = $operation->productionOrder;

            if ($order === null || $order->status === 'planned' || $operation->is_subcontracted || ! isset(self::WorkOrderStatus[$order->status])) {
                continue;
            }

            $ideal = IdealCycleTime::resolve($operation, $order);

            $data[(string) $operation->id] = [
                'production_order_operation_id' => (string) $operation->id,
                'work_order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'operation_sequence' => (int) $operation->sequence,
                'machine_id' => $operation->machine_center_id,
                'work_center_id' => (string) $operation->work_center_id,
                'item_id' => (string) $order->mfg_item_id,
                'item_sku' => $itemCodes[$order->mfg_item_id] ?? null,
                'planned_quantity' => Money::qty($order->target_quantity),
                'ideal_cycle_time_ms' => (float) $ideal['ms'],
                'ict_source' => $ideal['source'],
                'setup_standard_ms' => (int) Money::round(Money::mul($operation->planned_setup_minutes, '60000'), 0),
                'scheduled_start' => ($operation->scheduled_date ?? $order->planned_start_date)->toDateString(),
                'scheduled_end' => $order->planned_end_date->toDateString(),
                'status' => self::WorkOrderStatus[$order->status],
                'erp_status_raw' => $order->status,
            ];
        }

        return $data;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function shiftInstances(string $tenantId, array $ids): array
    {
        $data = [];

        foreach (MfgShiftInstance::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->get() as $shift) {
            $data[(string) $shift->id] = [
                'scope_type' => $shift->scope_type,
                'scope_id' => (string) $shift->scope_id,
                'shift_code' => $shift->shift_code,
                'starts_at' => $shift->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $shift->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'timezone' => $shift->timezone,
                'non_production' => $shift->non_production ?? [],
            ];
        }

        return $data;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function reasonCodes(string $tenantId, array $ids): array
    {
        $data = [];

        foreach (MfgReasonCode::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->with('labels')->get() as $reason) {
            $data[(string) $reason->id] = [
                'code' => $reason->code,
                'category' => $reason->category,
                'classification' => $reason->classification,
                'active' => (bool) $reason->is_active,
                'labels' => $reason->labels->pluck('label', 'locale')->all(),
            ];
        }

        return $data;
    }
}
