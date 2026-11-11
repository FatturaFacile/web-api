<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

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
