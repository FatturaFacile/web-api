<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\ProductionEngineering;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Manufacturing\Enums\OeeCalculationGranularity;
use Modules\Manufacturing\Enums\OeeLossCategory;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgOeeLog;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * Overall equipment effectiveness: availability x performance x quality.
 *
 * Planned production time = scheduled shift minutes of the work centre calendar (holidays and shutdowns excluded; the
 * window length without a calendar) minus planned downtime. Operating time = planned time minus unplanned downtime.
 * Performance = processed units x ideal cycle time / operating time. Quality = good / (good + scrap + rework).
 * Every factor is clamped to 0..1.
 */
final class OeeCalculator
{
    /** Longest window and largest number of buckets of a series. */
    private const int MaxDays = 366;

    private const int MaxBuckets = 800;

    /** Minutes without a telemetry ping after which a machine is reported stale. */
    private const int StaleAfterMinutes = 15;

    public function __construct(private readonly WorkingCalendarResolver $calendars) {}

    /**
     * Summary of the whole window and its time series.
     *
     * @return array<string, mixed>
     */
    public function calculate(MfgWorkCenter $workCenter, ?MfgMachineCenter $machine, CarbonImmutable $from, CarbonImmutable $to, OeeCalculationGranularity $granularity, ?CarbonImmutable $now = null): array
    {
        if ($to <= $from || $from->diffInDays($to, true) > self::MaxDays) {
            throw new ManufacturingOperationException('The period must be positive and at most 366 days.', 'to_datetime');
        }

        $workCenter->loadMissing('workingCalendar');
        $logs = MfgOeeLog::query()
            ->where('work_center_id', $workCenter->id)
            ->when($machine !== null, static fn ($query) => $query->where('machine_center_id', $machine?->id))
            ->where('period_start', '>=', $from)->where('period_start', '<', $to)
            ->get();
        $ideal = $this->idealCycleMinutes($workCenter, $machine);

        $series = [];
        foreach ($this->buckets($workCenter, $from, $to, $granularity) as [$start, $end]) {
            $series[] = ['from' => $start->toIso8601String(), 'to' => $end->toIso8601String()] + $this->metrics($workCenter, $logs->filter(static fn (MfgOeeLog $log): bool => $log->period_start >= $start && $log->period_start < $end), $start, $end, $ideal);
        }

        $summary = $this->metrics($workCenter, $logs, $from, $to, $ideal);
        $targets = [
            'oee' => (float) $workCenter->oee_target_percent,
            'availability' => $workCenter->availability_target_percent !== null ? (float) $workCenter->availability_target_percent : null,
            'performance' => $workCenter->performance_target_percent !== null ? (float) $workCenter->performance_target_percent : null,
            'quality' => $workCenter->quality_target_percent !== null ? (float) $workCenter->quality_target_percent : null,
        ];

        return [
            'work_center_id' => (string) $workCenter->id,
            'machine_center_id' => $machine?->id,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'granularity' => $granularity->value,
            'summary' => $summary,
            'targets_percent' => $targets,
            'meets_target' => $summary['oee_percent'] >= $targets['oee'],
            'gap_to_target_percent' => round($summary['oee_percent'] - $targets['oee'], 2),
            'six_big_losses' => $this->losses($logs, $ideal),
            'series' => $series,
            'machines' => $this->telemetry($workCenter, $machine, $now ?? CarbonImmutable::now()),
        ];
    }

    /**
     * Availability, performance, quality and OEE of one period.
     *
     * @param  Collection<int, MfgOeeLog>  $logs
     * @return array<string, mixed>
     */
    public function metrics(MfgWorkCenter $workCenter, Collection $logs, CarbonImmutable $from, CarbonImmutable $to, float $idealCycleMinutes): array
    {
        $scheduled = $workCenter->workingCalendar !== null
            ? $this->calendars->availableMinutes($workCenter->workingCalendar, $from, $to)
            : $from->diffInSeconds($to, true) / 60;
        $planned = max(0.0, $scheduled - (float) $logs->sum(static fn (MfgOeeLog $log): float => (float) $log->planned_downtime_minutes));
        $unplanned = (float) $logs->sum(static fn (MfgOeeLog $log): float => (float) $log->unplanned_downtime_minutes);
        $operating = max(0.0, $planned - $unplanned);

        $good = (float) $logs->sum(static fn (MfgOeeLog $log): float => (float) $log->good_units);
        $scrap = (float) $logs->sum(static fn (MfgOeeLog $log): float => (float) $log->scrap_units);
        $rework = (float) $logs->sum(static fn (MfgOeeLog $log): float => (float) $log->rework_units);
        $processed = $good + $scrap + $rework;

        $availability = $planned > 0 ? $this->clamp($operating / $planned) : 0.0;
        $performance = $operating > 0 ? $this->clamp($processed * $idealCycleMinutes / $operating) : 0.0;
        $quality = $processed > 0 ? $this->clamp($good / $processed) : 1.0;
        $oee = $availability * $performance * $quality;

        return [
            'scheduled_minutes' => round($scheduled, 3),
            'planned_production_minutes' => round($planned, 3),
            'unplanned_downtime_minutes' => round($unplanned, 3),
            'operating_minutes' => round($operating, 3),
            'good_units' => round($good, 4),
            'scrap_units' => round($scrap, 4),
            'rework_units' => round($rework, 4),
            'total_units' => round($processed, 4),
            'ideal_cycle_minutes_per_unit' => round($idealCycleMinutes, 6),
            'availability' => round($availability, 6),
            'performance' => round($performance, 6),
            'quality' => round($quality, 6),
            'oee' => round($oee, 6),
            'availability_percent' => round($availability * 100, 2),
            'performance_percent' => round($performance * 100, 2),
            'quality_percent' => round($quality * 100, 2),
            'oee_percent' => round($oee * 100, 2),
        ];
    }

    /**
     * Ideal cycle minutes per unit: the machine's, else the mean of the machines of the work centre, else the mean
     * run time per unit of the routing operations on the work centre.
     */
    private function idealCycleMinutes(MfgWorkCenter $workCenter, ?MfgMachineCenter $machine): float
    {
        if ($machine !== null && (float) $machine->ideal_cycle_time_seconds_per_unit > 0) {
            return (float) $machine->ideal_cycle_time_seconds_per_unit / 60;
        }

        if ($machine === null) {
            $cycles = MfgMachineCenter::query()->where('work_center_id', $workCenter->id)->where('ideal_cycle_time_seconds_per_unit', '>', 0)->pluck('ideal_cycle_time_seconds_per_unit');
            if ($cycles->isNotEmpty()) {
                return (float) $cycles->avg() / 60;
            }
        }

        return (float) (MfgOperation::query()->where('work_center_id', $workCenter->id)->where('run_time_minutes_per_unit', '>', 0)->avg('run_time_minutes_per_unit') ?? 0);
    }

    /**
     * The buckets of the series: hours, shifts, days, weeks (Monday), months, or the whole window.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function buckets(MfgWorkCenter $workCenter, CarbonImmutable $from, CarbonImmutable $to, OeeCalculationGranularity $granularity): array
    {
        $timezone = $workCenter->working_calendar_id !== null ? $workCenter->workingCalendar->timezone : 'UTC';
        $from = $from->setTimezone($timezone);
        $to = $to->setTimezone($timezone);

        if ($granularity === OeeCalculationGranularity::CustomRange) {
            return [[$from, $to]];
        }

        if ($granularity === OeeCalculationGranularity::Shift && $workCenter->workingCalendar !== null) {
            $buckets = [];
            foreach ($this->calendars->preview($workCenter->workingCalendar, $from, $to) as $day) {
                foreach ($day['shifts'] as $shift) {
                    $start = max($shift['start_at'], $from);
                    $end = min($shift['end_at'], $to);
                    if ($end > $start) {
                        $buckets[] = [$start, $end];
                    }
                }
            }

            return array_slice($buckets, 0, self::MaxBuckets);
        }

        $step = match ($granularity) {
            OeeCalculationGranularity::RealTime => 'hour',
            OeeCalculationGranularity::Weekly => 'week',
            OeeCalculationGranularity::Monthly => 'month',
            default => 'day',
        };

        $buckets = [];
        $cursor = $from;
        while ($cursor < $to && count($buckets) < self::MaxBuckets) {
            $next = match ($step) {
                'hour' => $cursor->startOfHour()->addHour(),
                'week' => $cursor->startOfWeek()->addWeek(),
                'month' => $cursor->startOfMonth()->addMonth(),
                default => $cursor->startOfDay()->addDay(),
            };
            $end = min($next, $to);
            $buckets[] = [$cursor, $end];
            $cursor = $end;
        }

        return $buckets;
    }

    /**
     * Lost minutes by big loss: failures and set-ups from unplanned downtime, minor stops and reduced speed from the
     * logged categories, defects and start-up rejects from the lost units at the ideal cycle time.
     *
     * @param  Collection<int, MfgOeeLog>  $logs
     * @return array<string, array{minutes: float, factor: string}>
     */
    private function losses(Collection $logs, float $ideal): array
    {
        $losses = [];
        foreach (OeeLossCategory::cases() as $case) {
            $losses[$case->value] = ['minutes' => 0.0, 'factor' => $case->factor()];
        }

        foreach ($logs as $log) {
            $category = OeeLossCategory::tryFrom((string) $log->loss_category) ?? OeeLossCategory::EquipmentFailure;
            $losses[$category->value]['minutes'] += (float) $log->unplanned_downtime_minutes;

            $defectCategory = $category === OeeLossCategory::StartupRejects ? OeeLossCategory::StartupRejects : OeeLossCategory::ProcessDefects;
            $losses[$defectCategory->value]['minutes'] += ((float) $log->scrap_units + (float) $log->rework_units) * $ideal;
        }

        foreach ($losses as $key => $loss) {
            $losses[$key]['minutes'] = round($loss['minutes'], 3);
        }

        return $losses;
    }

    /**
     * Telemetry status of the Industria 4.0 / Transizione 5.0 machines in scope.
     *
     * @return list<array<string, mixed>>
     */
    private function telemetry(MfgWorkCenter $workCenter, ?MfgMachineCenter $machine, CarbonImmutable $now): array
    {
        $machines = MfgMachineCenter::query()->where('work_center_id', $workCenter->id)
            ->when($machine !== null, static fn ($query) => $query->whereKey($machine?->id))
            ->orderBy('machine_code')->get();

        return $machines->map(static function (MfgMachineCenter $item) use ($now): array {
            $seen = $item->telemetry_last_seen_at;
            $status = match (true) {
                $seen === null => 'never',
                $seen->lt($now->subMinutes(self::StaleAfterMinutes)) => 'stale',
                default => 'online',
            };

            return [
                'machine_center_id' => (string) $item->id,
                'machine_code' => $item->machine_code,
                'industria_40' => $item->is_industria_40_enabled,
                'transizione_50' => $item->is_transizione_50_enabled,
                'telemetry_status' => $status,
                'telemetry_last_seen_at' => $seen?->toIso8601String(),
            ];
        })->all();
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
