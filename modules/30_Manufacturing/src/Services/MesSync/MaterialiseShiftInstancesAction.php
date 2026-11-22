<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesSync;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Manufacturing\Events\MesShiftCalendarUpdatedEvent;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgShiftInstance;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;
use Modules\Manufacturing\Models\MfgWorkCenterShift;
use Modules\Manufacturing\Services\WorkCenterService;
use Modules\Manufacturing\Support\Money;

/**
 * Expands the weekly calendar of the active work centres and their dated exceptions (holidays, extra shifts) into UTC
 * shift instances over a horizon of days, so the MES Core reads intervals instead of interpreting weekdays and time
 * zones. The calendars only hold gross hours: the shift starts at the configured local time of the plant time zone and
 * lasts that many elapsed hours, which keeps the length right on a daylight-saving change.
 *
 * Idempotent: an instance whose interval did not change is not touched, one that is no longer wanted is deleted
 * (a tombstone for the MES Core) and a deleted one that is wanted again is restored.
 */
final readonly class MaterialiseShiftInstancesAction
{
    public function __construct(private MesChangeRecorder $recorder, private WorkCenterService $workCenters) {}

    /**
     * Materialises the instances of the tenant, of one work centre when given.
     *
     * @param  CarbonImmutable|null  $today  the first day of the horizon (the current day of the plant when null)
     * @return array{created: int, updated: int, deleted: int}
     */
    public function __invoke(string $tenantId, ?string $workCenterId = null, ?CarbonImmutable $today = null): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return app(TenantContext::class)->runWithTenant($tenant, function () use ($tenantId, $workCenterId, $today): array {
            $totals = ['created' => 0, 'updated' => 0, 'deleted' => 0];

            $workCenters = MfgWorkCenter::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->when($workCenterId !== null, static fn ($query) => $query->whereKey($workCenterId))
                ->get();

            foreach ($workCenters as $workCenter) {
                $counts = DB::transaction(fn (): array => $this->materialise($workCenter, $today));

                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }
            }

            return $totals;
        });
    }

    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    private function materialise(MfgWorkCenter $workCenter, ?CarbonImmutable $today): array
    {
        $timezone = (string) config('manufacturing_mes.shifts.timezone');
        $startTime = (string) config('manufacturing_mes.shifts.start_time');
        $code = (string) config('manufacturing_mes.shifts.code');
        $horizon = max(1, (int) config('manufacturing_mes.shifts.horizon_days'));
        $tenantId = (string) $workCenter->tenant_id;

        $firstDay = ($today ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $lastDay = $firstDay->addDays($horizon - 1);

        $shifts = MfgWorkCenterShift::query()->where('work_center_id', $workCenter->id)->get();
        $exceptions = MfgWorkCenterCapacity::query()
            ->where('work_center_id', $workCenter->id)
            ->whereDate('calendar_date', '>=', $firstDay->toDateString())
            ->whereDate('calendar_date', '<=', $lastDay->toDateString())
            ->get()
            ->keyBy(static fn (MfgWorkCenterCapacity $row): string => $row->calendar_date->toDateString());

        $maintenance = $this->plannedMaintenance($workCenter, $firstDay, $lastDay, $startTime, $timezone);

        /** @var array<string, array{starts: CarbonImmutable, ends: CarbonImmutable, holes: list<array<string, string|null>>}> $wanted */
        $wanted = [];
        for ($day = $firstDay; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            $hours = $this->workCenters->grossHours($workCenter, $day, $shifts, $exceptions);

            if (! Money::isPositive($hours)) {
                continue;
            }

            $starts = CarbonImmutable::parse($day->toDateString().' '.$startTime, $timezone)->utc();
            $ends = $starts->addSeconds((int) Money::round(Money::mul($hours, '3600'), 0));
            $wanted[self::key($starts)] = ['starts' => $starts, 'ends' => $ends, 'holes' => $this->holes($day, $starts, $ends, $timezone, $maintenance)];
        }

        $existing = MfgShiftInstance::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('scope_type', MfgShiftInstance::ScopeWorkCenter)
            ->where('scope_id', $workCenter->id)
            ->where('shift_code', $code)
            ->where('starts_at', '>=', $firstDay->utc())
            ->where('starts_at', '<', $lastDay->addDay()->utc())
            ->get()
            ->keyBy(static fn (MfgShiftInstance $row): string => self::key(CarbonImmutable::instance($row->starts_at)));

        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $lastSeq = null;

        foreach ($wanted as $key => $interval) {
            $row = $existing->get($key);

            if ($row === null) {
                $row = MfgShiftInstance::create([
                    'tenant_id' => $tenantId,
                    'scope_type' => MfgShiftInstance::ScopeWorkCenter,
                    'scope_id' => $workCenter->id,
                    'shift_code' => $code,
                    'starts_at' => $interval['starts'],
                    'ends_at' => $interval['ends'],
                    'timezone' => $timezone,
                    'non_production' => $interval['holes'],
                ]);
                $counts['created']++;
            } elseif ($row->trashed()) {
                $row->restore();
                $row->update(['ends_at' => $interval['ends'], 'timezone' => $timezone, 'non_production' => $interval['holes']]);
                $counts['created']++;
            } elseif (! $row->ends_at->equalTo($interval['ends']) || $row->timezone !== $timezone || ($row->non_production ?? []) !== $interval['holes']) {
                $row->update(['ends_at' => $interval['ends'], 'timezone' => $timezone, 'non_production' => $interval['holes']]);
                $counts['updated']++;
            } else {
                continue;
            }

            $lastSeq = $this->recorder->record($tenantId, MesChangeRecorder::ShiftCalendars, (string) $row->id);
        }

        foreach ($existing as $key => $row) {
            if (! isset($wanted[$key]) && ! $row->trashed()) {
                $row->delete();
                $counts['deleted']++;
                $lastSeq = $this->recorder->record($tenantId, MesChangeRecorder::ShiftCalendars, (string) $row->id, MesChangeRecorder::Delete);
            }
        }

        if ($lastSeq !== null) {
            $this->recorder->notify(MesShiftCalendarUpdatedEvent::class, $tenantId, MesChangeRecorder::ShiftCalendars, (string) $workCenter->id, $lastSeq);
        }

        return $counts;
    }

    /**
     * The non-production windows of one shift, in UTC: the configured breaks of the plant (no machine: they apply to
     * every machine of the work centre) and the planned maintenance of single machines, clipped to the shift.
     *
     * @param  list<array{machine_id: string, from: CarbonImmutable, to: CarbonImmutable, reference: string}>  $maintenance
     * @return list<array<string, string|null>>
     */
    private function holes(CarbonImmutable $day, CarbonImmutable $starts, CarbonImmutable $ends, string $timezone, array $maintenance): array
    {
        $holes = [];

        foreach ((array) config('manufacturing_mes.shifts.breaks') as $break) {
            if (! is_array($break) || ! is_string($break['start'] ?? null) || (int) ($break['minutes'] ?? 0) <= 0) {
                continue;
            }

            $from = CarbonImmutable::parse($day->toDateString().' '.$break['start'], $timezone)->utc();
            $to = $from->addMinutes((int) $break['minutes']);

            if ($from->greaterThanOrEqualTo($starts) && $to->lessThanOrEqualTo($ends)) {
                $holes[] = ['kind' => 'break', 'machine_id' => null, 'from' => self::iso($from), 'to' => self::iso($to), 'reference' => null];
            }
        }

        foreach ($maintenance as $window) {
            $from = $window['from']->greaterThan($starts) ? $window['from'] : $starts;
            $to = $window['to']->lessThan($ends) ? $window['to'] : $ends;

            if ($to->greaterThan($from)) {
                $holes[] = ['kind' => 'planned_maintenance', 'machine_id' => $window['machine_id'], 'from' => self::iso($from), 'to' => self::iso($to), 'reference' => $window['reference']];
            }
        }

        usort($holes, static fn (array $a, array $b): int => [$a['from'], $a['kind'], $a['machine_id']] <=> [$b['from'], $b['kind'], $b['machine_id']]);

        return $holes;
    }

    /**
     * The planned maintenance of the machines of the work centre (asset maintenance work orders that are scheduled or in
     * progress, preventive, condition based or upgrade, with a planned downtime). The window starts when the work order
     * started, or at the shift start of its scheduled day, and lasts the planned downtime hours. Corrective and
     * emergency work is unplanned: it shows up as machine downtime, not as excluded time.
     *
     * @return list<array{machine_id: string, from: CarbonImmutable, to: CarbonImmutable, reference: string}>
     */
    private function plannedMaintenance(MfgWorkCenter $workCenter, CarbonImmutable $firstDay, CarbonImmutable $lastDay, string $startTime, string $timezone): array
    {
        $machines = MfgMachineCenter::query()->where('tenant_id', $workCenter->tenant_id)->where('work_center_id', $workCenter->id)->whereNotNull('asset_id')->pluck('asset_id', 'id');

        if ($machines->isEmpty() || ! Schema::hasTable('eam_maintenance_work_orders')) {
            return [];
        }

        $orders = DB::table('eam_maintenance_work_orders')
            ->where('tenant_id', $workCenter->tenant_id)
            ->whereIn('asset_id', $machines->values()->all())
            ->whereNull('deleted_at')
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereIn('maintenance_type', ['preventive', 'condition_based', 'upgrade'])
            ->where('downtime_hours', '>', 0)
            ->whereDate('scheduled_date', '>=', $firstDay->subDay()->toDateString())
            ->whereDate('scheduled_date', '<=', $lastDay->toDateString())
            ->get(['asset_id', 'work_order_number', 'scheduled_date', 'started_at', 'downtime_hours']);

        $windows = [];

        foreach ($orders as $order) {
            $from = $order->started_at !== null
                ? CarbonImmutable::parse((string) $order->started_at, 'UTC')
                : CarbonImmutable::parse(CarbonImmutable::parse((string) $order->scheduled_date)->toDateString().' '.$startTime, $timezone)->utc();
            $seconds = (int) Money::round(Money::mul(Money::of((string) $order->downtime_hours), '3600'), 0);

            foreach ($machines->filter(static fn (mixed $assetId): bool => (string) $assetId === (string) $order->asset_id)->keys() as $machineId) {
                $windows[] = ['machine_id' => (string) $machineId, 'from' => $from, 'to' => $from->addSeconds($seconds), 'reference' => (string) $order->work_order_number];
            }
        }

        return $windows;
    }

    private static function iso(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function key(CarbonImmutable $startsAt): string
    {
        return $startsAt->utc()->format('Y-m-d\TH:i:s');
    }
}
