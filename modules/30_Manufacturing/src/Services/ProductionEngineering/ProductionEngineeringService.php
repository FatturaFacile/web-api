<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\ProductionEngineering;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Events\ProductionRoutingDefinedEvent;
use Modules\Manufacturing\Events\WorkCenterCreatedEvent;
use Modules\Manufacturing\Events\WorkCenterUpdatedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgOeeLog;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgRoutingStepBomComponent;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkingCalendar;
use Modules\Manufacturing\Models\MfgWorkingCalendarClosure;
use Modules\Manufacturing\Models\MfgWorkingCalendarShift;
use Modules\Manufacturing\Support\Money;

/**
 * Production engineering master data: working calendars, work centre hierarchy and alternates, machines with
 * telemetry, routing redefinition and revisions, and OEE logs.
 */
final readonly class ProductionEngineeringService
{
    public function __construct(
        private RoutingOperationWriter $writer,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Creates or updates a working calendar and replaces its shifts and shutdowns when they are given.
     *
     * @param  array<string, mixed>  $data
     */
    public function configureCalendar(string $tenantId, ?MfgWorkingCalendar $calendar, array $data): MfgWorkingCalendar
    {
        return DB::transaction(function () use ($tenantId, $calendar, $data): MfgWorkingCalendar {
            $attributes = array_intersect_key($data, array_flip(['code', 'name', 'timezone', 'country_code', 'region_code', 'inherit_holiday_calendar_id', 'is_default']));
            if (isset($attributes['country_code'])) {
                $attributes['country_code'] = strtoupper((string) $attributes['country_code']);
            }
            if (isset($attributes['region_code'])) {
                $attributes['region_code'] = strtoupper((string) $attributes['region_code']);
            }

            if ($calendar === null) {
                $calendar = MfgWorkingCalendar::create([...$attributes, 'tenant_id' => $tenantId]);
            } else {
                $calendar->update($attributes);
            }

            if (($data['is_default'] ?? false) === true) {
                MfgWorkingCalendar::query()->where('tenant_id', $tenantId)->whereKeyNot($calendar->id)->update(['is_default' => false]);
            }

            if (array_key_exists('shifts', $data)) {
                MfgWorkingCalendarShift::query()->where('working_calendar_id', $calendar->id)->forceDelete();
                foreach ((array) $data['shifts'] as $shift) {
                    $net = MfgWorkingCalendarShift::netMinutes((string) $shift['start_time'], (string) $shift['end_time'], (int) ($shift['break_minutes'] ?? 0));
                    MfgWorkingCalendarShift::create([
                        'tenant_id' => $tenantId,
                        'working_calendar_id' => $calendar->id,
                        'day_of_week' => $shift['day_of_week'],
                        'shift_code' => $shift['shift_code'],
                        'start_time' => $shift['start_time'],
                        'end_time' => $shift['end_time'],
                        'crosses_midnight' => MfgWorkingCalendarShift::minuteOfDay((string) $shift['end_time']) <= MfgWorkingCalendarShift::minuteOfDay((string) $shift['start_time']),
                        'break_minutes' => (int) ($shift['break_minutes'] ?? 0),
                        'net_available_minutes' => $net,
                        'labor_multiplier' => Money::round($shift['labor_multiplier'] ?? '1', 4),
                    ]);
                }
            }

            if (array_key_exists('closures', $data)) {
                MfgWorkingCalendarClosure::query()->where('working_calendar_id', $calendar->id)->forceDelete();
                foreach ((array) $data['closures'] as $closure) {
                    MfgWorkingCalendarClosure::create([
                        'tenant_id' => $tenantId,
                        'working_calendar_id' => $calendar->id,
                        'starts_on' => $closure['starts_on'],
                        'ends_on' => $closure['ends_on'],
                        'reason' => $closure['reason'] ?? null,
                    ]);
                }
            }

            return $calendar->load(['shifts', 'closures']);
        });
    }

    /**
     * Rejects a parent that would make the hierarchy circular and alternates that are the work centre itself, missing
     * from the company, or that name the work centre as their own alternate.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertWorkCenterReferences(string $tenantId, ?MfgWorkCenter $current, array $data): void
    {
        $parentId = $data['parent_work_center_id'] ?? null;
        if ($parentId !== null && $current !== null) {
            $cursor = (string) $parentId;
            for ($depth = 0; $cursor !== '' && $depth < 50; $depth++) {
                if ($cursor === (string) $current->id) {
                    throw new ManufacturingOperationException('The parent would make the work centre hierarchy circular.', 'parent_work_center_id');
                }
                $cursor = (string) (MfgWorkCenter::query()->where('tenant_id', $tenantId)->whereKey($cursor)->value('parent_work_center_id') ?? '');
            }
        }

        $alternates = $this->writer->alternates($tenantId, $data['alternate_work_center_ids'] ?? null, $current !== null ? (string) $current->id : '');
        if ($alternates !== null && $current !== null) {
            $mutual = MfgWorkCenter::query()->where('tenant_id', $tenantId)->whereIn('id', $alternates)->get()
                ->first(static fn (MfgWorkCenter $alternate): bool => in_array((string) $current->id, (array) $alternate->alternate_work_center_ids, true));
            if ($mutual !== null) {
                throw new ManufacturingOperationException("Work centre [{$mutual->work_center_code}] already lists this work centre as its alternate.", 'alternate_work_center_ids');
            }
        }
    }

    /**
     * Publishes the creation or the change of a work centre.
     */
    public function workCenterSaved(MfgWorkCenter $workCenter, bool $created): void
    {
        $event = $created
            ? new WorkCenterCreatedEvent((string) $workCenter->id, $workCenter->work_center_code, (string) $workCenter->tenant_id)
            : new WorkCenterUpdatedEvent((string) $workCenter->id, $workCenter->work_center_code, (string) $workCenter->tenant_id);
        $this->dispatcher->publishAndDispatch($event);
    }

    /**
     * Work centre with its descendants and machines, as a tree.
     *
     * @return array<string, mixed>
     */
    public function hierarchy(MfgWorkCenter $root): array
    {
        return $this->node($root, 0);
    }

    /**
     * Redefines a routing: header changes and, when given, the whole operation list with its component links.
     *
     * @param  array<string, mixed>  $data
     */
    public function redefineRouting(MfgRouting $routing, array $data): MfgRouting
    {
        return DB::transaction(function () use ($routing, $data): MfgRouting {
            MfgRouting::query()->whereKey($routing->id)->lockForUpdate()->firstOrFail();
            $routing->update(array_intersect_key($data, array_flip(['name', 'status', 'effective_from', 'effective_to', 'notes', 'bom_id'])));

            if (($data['is_default'] ?? false) === true) {
                MfgRouting::query()->where('tenant_id', $routing->tenant_id)->where('mfg_item_id', $routing->mfg_item_id)->whereKeyNot($routing->id)->update(['is_default' => false]);
                $routing->update(['is_default' => true]);
            }

            if (array_key_exists('operations', $data)) {
                $ids = MfgOperation::query()->where('routing_id', $routing->id)->pluck('id');
                MfgRoutingStepBomComponent::query()->whereIn('operation_id', $ids)->forceDelete();
                MfgOperation::query()->where('routing_id', $routing->id)->forceDelete();
                $this->writer->write((string) $routing->tenant_id, $routing, array_values((array) $data['operations']));
            }

            $this->dispatcher->publishAndDispatch(new ProductionRoutingDefinedEvent((string) $routing->id, $routing->routing_number, (string) $routing->tenant_id));

            return $routing->refresh()->load('operations.workCenter');
        });
    }

    /**
     * Copies a routing with its operations and links into the next revision (A to B, 1.0 to 2.0 by incrementing the
     * last character or number) as a draft.
     */
    public function cloneRevision(MfgRouting $routing, ?string $revision = null): MfgRouting
    {
        return DB::transaction(function () use ($routing, $revision): MfgRouting {
            $revision ??= $this->nextRevision($routing->revision);
            $exists = MfgRouting::query()->where('tenant_id', $routing->tenant_id)->where('routing_number', $routing->routing_number)->where('revision', $revision)->exists();
            if ($exists) {
                throw new ManufacturingOperationException("Routing [{$routing->routing_number}] already has revision [{$revision}].", 'revision');
            }

            $copy = MfgRouting::create([
                ...$routing->only(['tenant_id', 'routing_number', 'mfg_item_id', 'name', 'effective_from', 'effective_to', 'notes', 'bom_id']),
                'revision' => $revision,
                'status' => 'draft',
                'is_default' => false,
            ]);

            foreach (MfgOperation::query()->with('bomComponents')->where('routing_id', $routing->id)->orderBy('sequence')->get() as $operation) {
                $new = MfgOperation::create([
                    ...collect($operation->getAttributes())->except(['id', 'routing_id', 'created_at', 'updated_at', 'deleted_at'])->all(),
                    'routing_id' => $copy->id,
                ]);
                foreach ($operation->bomComponents as $link) {
                    MfgRoutingStepBomComponent::create([...$link->only(['tenant_id', 'bom_component_id', 'backflush_on_completion', 'notes']), 'operation_id' => $new->id]);
                }
            }

            $this->dispatcher->publishAndDispatch(new ProductionRoutingDefinedEvent((string) $copy->id, $copy->routing_number, (string) $copy->tenant_id));

            return $copy->load('operations.workCenter');
        });
    }

    /**
     * Saves a machine; its work centre, serial and telemetry linkage are checked by the form request.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveMachine(string $tenantId, ?MfgMachineCenter $machine, array $data): MfgMachineCenter
    {
        $attributes = array_intersect_key($data, array_flip([
            'machine_code', 'name', 'work_center_id', 'asset_id', 'maintenance_schedule_id', 'manufacturer', 'model', 'serial_number', 'is_industria_40_enabled', 'is_transizione_50_enabled',
            'telemetry_device_id', 'telemetry_endpoint_key', 'parallel_capacity_units', 'status', 'power_kw', 'hourly_rate',
        ]));
        foreach (['ideal_cycle_time_seconds_per_unit' => 4, 'efficiency_factor_percent' => 2, 'hourly_machine_run_cost_rate_override' => 4] as $field => $decimals) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === null ? null : Money::round((string) $data[$field], $decimals);
            }
        }

        if ($machine === null) {
            return MfgMachineCenter::create([...$attributes, 'tenant_id' => $tenantId]);
        }

        $machine->update($attributes);

        return $machine->refresh();
    }

    /**
     * Records a telemetry ping of an Industria 4.0 / Transizione 5.0 machine and, when it carries production counters,
     * an OEE log. The key must match the machine's endpoint key.
     *
     * @param  array<string, mixed>  $data
     */
    public function telemetryPing(MfgMachineCenter $machine, array $data): MfgMachineCenter
    {
        if (! $machine->is_industria_40_enabled && ! $machine->is_transizione_50_enabled) {
            throw new ManufacturingOperationException('The machine is not enabled for Industria 4.0 / Transizione 5.0 telemetry.', 'machine');
        }
        if ($machine->telemetry_endpoint_key === null || ! hash_equals($machine->telemetry_endpoint_key, (string) ($data['telemetry_endpoint_key'] ?? ''))) {
            throw new ManufacturingOperationException('The telemetry endpoint key does not match.', 'telemetry_endpoint_key');
        }

        $seen = isset($data['recorded_at']) ? CarbonImmutable::parse((string) $data['recorded_at']) : CarbonImmutable::now();
        $machine->update(['telemetry_last_seen_at' => $seen, 'telemetry_status' => (string) ($data['status'] ?? 'online')]);

        if (isset($data['period_start'], $data['period_end'])) {
            $this->recordLog((string) $machine->tenant_id, [
                ...$data,
                'work_center_id' => $machine->work_center_id,
                'machine_center_id' => $machine->id,
                'source' => 'telemetry',
            ]);
        }

        return $machine->refresh();
    }

    /**
     * Records production execution data of a work centre or machine over a period.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordLog(string $tenantId, array $data): MfgOeeLog
    {
        $workCenterId = (string) $data['work_center_id'];
        if (isset($data['machine_center_id'])) {
            $belongs = MfgMachineCenter::query()->where('tenant_id', $tenantId)->where('work_center_id', $workCenterId)->whereKey($data['machine_center_id'])->exists();
            if (! $belongs) {
                throw new ManufacturingOperationException('The machine does not belong to the work centre.', 'machine_center_id');
            }
        }

        return MfgOeeLog::create([
            'tenant_id' => $tenantId,
            'work_center_id' => $workCenterId,
            'machine_center_id' => $data['machine_center_id'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'period_start' => CarbonImmutable::parse((string) $data['period_start']),
            'period_end' => CarbonImmutable::parse((string) $data['period_end']),
            'planned_downtime_minutes' => Money::round($data['planned_downtime_minutes'] ?? '0', 3),
            'unplanned_downtime_minutes' => Money::round($data['unplanned_downtime_minutes'] ?? '0', 3),
            'loss_category' => $data['loss_category'] ?? null,
            'good_units' => Money::round($data['good_units'] ?? '0', 4),
            'scrap_units' => Money::round($data['scrap_units'] ?? '0', 4),
            'rework_units' => Money::round($data['rework_units'] ?? '0', 4),
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(MfgWorkCenter $workCenter, int $depth): array
    {
        $children = $depth >= 20 ? collect() : MfgWorkCenter::query()->where('tenant_id', $workCenter->tenant_id)->where('parent_work_center_id', $workCenter->id)->orderBy('work_center_code')->get();

        return [
            'id' => (string) $workCenter->id,
            'work_center_code' => $workCenter->work_center_code,
            'name' => $workCenter->name,
            'status' => $workCenter->status,
            'machines' => MfgMachineCenter::query()->where('work_center_id', $workCenter->id)->orderBy('machine_code')->get(['id', 'machine_code', 'name', 'status'])->toArray(),
            'children' => $children->map(fn (MfgWorkCenter $child): array => $this->node($child, $depth + 1))->all(),
        ];
    }

    private function nextRevision(string $revision): string
    {
        if (preg_match('/^(.*?)(\d+)(\.\d+)?$/', $revision, $matches) === 1) {
            return $matches[1].((int) $matches[2] + 1).(isset($matches[3]) ? '.0' : '');
        }

        $last = substr($revision, -1);

        return ctype_alpha($last) ? substr($revision, 0, -1).chr(ord($last) + 1) : $revision.'1';
    }
}
