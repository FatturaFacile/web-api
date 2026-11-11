<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Analytics\Models\KpiSnapshot;
use Modules\Analytics\Support\ReportPeriod;
use Throwable;

/**
 * Materialises the heaviest KPIs once a day, per company, so a dashboard reads a row of `analytics_kpi_snapshots`
 * instead of scanning invoices, the ledger and the stock. A period KPI is stored as the month to date at the snapshot
 * day, a snapshot KPI (aging, stock) as its position when the refresh ran. The refresh is idempotent per day: running it
 * again replaces the row of the day with a fresh calculation. The KPI definitions are not duplicated: the snapshot calls
 * the same calculators the live endpoints use, so a snapshot can never disagree with its definition.
 */
final readonly class KpiSnapshotService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private KpiCatalog $catalog, private TenantClock $clock) {}

    /**
     * The KPI report served from the snapshots, in the shape of {@see KpiCatalog::report()} plus `source` and
     * `snapshot_date` on every indicator. The snapshot is the latest one up to the end of the requested period (the
     * `from` date is ignored: a snapshot is the month to date of its day). Indicators that are not snapshotted, or have no
     * snapshot yet, are calculated live, so a dashboard never shows a hole; an indicator the caller cannot read is
     * reported as unavailable, as in the live report.
     *
     * @param  list<string>  $keys  empty for every indicator
     * @return array{period: array{from: string, to: string}, comparison_period: ?array{from: string, to: string}, kpis: list<array<string, mixed>>}
     */
    public function report(array $keys, ReportPeriod $period, User $user): array
    {
        $keys = $keys === [] ? array_keys($this->catalog->calculators()) : $keys;
        $entries = [];
        $snapshotPeriod = null;
        $comparisonPeriod = null;

        foreach ($keys as $key) {
            $calculator = $this->catalog->calculator($key);
            $snapshot = $this->catalog->canRead($calculator, $user) && in_array($key, $this->keys(), true) ? $this->latest($key, $period->to) : null;

            if ($snapshot === null) {
                $entries[] = [...$this->catalog->report([$key], $period, $user)['kpis'][0], 'source' => 'live', 'snapshot_date' => null];

                continue;
            }

            $snapshotPeriod ??= ['from' => $snapshot->period_from->toDateString(), 'to' => $snapshot->period_to->toDateString()];
            $entry = [
                'key' => $calculator->key(), 'label' => $calculator->label(), 'unit' => $calculator->unit(), 'kind' => $calculator->kind(),
                'available' => true, 'reason' => null, 'value' => $snapshot->value, 'details' => $snapshot->details,
                'comparison' => null, 'change' => null, 'change_percent' => null, 'source' => 'snapshot', 'snapshot_date' => $snapshot->snapshot_date->toDateString(),
            ];

            if ($period->compare !== ReportPeriod::CompareNone) {
                if ($calculator->kind() === 'snapshot') {
                    $entry['reason'] = 'snapshot_not_comparable';
                } else {
                    $earlier = $period->compare === ReportPeriod::ComparePreviousYear ? $snapshot->snapshot_date->subYearNoOverflow() : $snapshot->snapshot_date->subMonthNoOverflow();
                    $before = KpiSnapshot::query()->where('kpi_key', $key)->whereDate('snapshot_date', $earlier->toDateString())->first();

                    if ($before !== null) {
                        $comparisonPeriod ??= ['from' => $before->period_from->toDateString(), 'to' => $before->period_to->toDateString()];
                        $entry['comparison'] = ['period' => ['from' => $before->period_from->toDateString(), 'to' => $before->period_to->toDateString()], 'value' => $before->value, 'details' => $before->details];

                        if ($snapshot->value !== null && $before->value !== null) {
                            $scale = $calculator->unit() === 'count' ? 0 : 2;
                            $entry['change'] = bcsub($snapshot->value, $before->value, $scale);
                            $base = ltrim($before->value, '-');
                            $entry['change_percent'] = bccomp($base, '0', $scale) === 0 ? null : bcround(bcdiv(bcmul($entry['change'], '100', 4), $base, 4), 2);
                        }
                    }
                }
            }

            $entries[] = $entry;
        }

        return ['period' => $snapshotPeriod ?? $period->toArray(), 'comparison_period' => $comparisonPeriod, 'kpis' => $entries];
    }

    /**
     * The latest snapshot of a KPI up to a day.
     */
    public function latest(string $key, CarbonImmutable $day): ?KpiSnapshot
    {
        return KpiSnapshot::query()->where('kpi_key', $key)->whereDate('snapshot_date', '<=', $day->toDateString())->orderByDesc('snapshot_date')->first();
    }

    /**
     * KPIs that are materialised.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_intersect(array_keys($this->catalog->calculators()), (array) config('analytics.snapshot_kpis', [])));
    }

    /**
     * The business day the nightly refresh describes: yesterday on the calendar of the company.
     */
    public function defaultDay(): CarbonImmutable
    {
        return $this->clock->today()->subDay();
    }

    /**
     * Calculates and stores the snapshots of a day for the active company. A KPI that fails is skipped, the others
     * are stored.
     *
     * @return array{stored: int, failed: list<string>}
     */
    public function refresh(?CarbonImmutable $day = null): array
    {
        $day = ($day ?? $this->defaultDay())->startOfDay();
        $period = new ReportPeriod($day->startOfMonth(), $day);
        $stored = 0;
        $failed = [];

        foreach ($this->keys() as $key) {
            try {
                $result = $this->catalog->calculator($key)->calculate($period);
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $key;

                continue;
            }

            KpiSnapshot::query()->updateOrCreate(
                ['kpi_key' => $key, 'snapshot_date' => $day->toDateString()],
                ['period_from' => $period->from->toDateString(), 'period_to' => $period->to->toDateString(), 'value' => $result['value'], 'details' => $result['details'], 'computed_at' => now()],
            );
            $stored++;
        }

        return ['stored' => $stored, 'failed' => $failed];
    }

    /**
     * The stored series of a KPI, oldest first.
     *
     * @return Collection<int, KpiSnapshot>
     */
    public function series(string $key, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return KpiSnapshot::query()
            ->where('kpi_key', $key)
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('snapshot_date')
            ->limit(400)
            ->get();
    }

    /**
     * Removes the snapshots older than the given number of days.
     */
    public function prune(int $keepDays = 800): int
    {
        return KpiSnapshot::query()->where('snapshot_date', '<', $this->clock->today()->subDays($keepDays)->toDateString())->forceDelete();
    }
}
