<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Domain\Support\Distribution;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\BenchmarkDimension;
use Modules\FranchiseNetwork\Models\BenchmarkNetworkStat;
use Modules\FranchiseNetwork\Models\BenchmarkSnapshot;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\SellOutReport;

/**
 * Anonymous benchmark of the stores. A monthly snapshot keeps the KPIs of each store (like-for-like growth, average ticket,
 * conversion, stock rotation, net sell-out); the network statistics keep only the average, median and top-quartile threshold
 * of the whole network and of each size cluster. A franchisee reads its own value next to those aggregates: never the value of
 * another store. A cluster with fewer stores than the minimum is hidden (the network aggregate stays), so no store can be deduced.
 */
final class BenchmarkService
{
    public const string ALL = 'all';

    /**
     * Builds the snapshot of every active store that reported its sell-out and the network statistics of the month.
     *
     * @return array{stores: int, stats: int}
     */
    public function snapshot(string $period): array
    {
        BillingPeriod::assert($period);
        $reports = SellOutReport::query()->where('period', $period)->get()->keyBy('store_id');
        $stores = FranchiseStore::query()->where('status', StoreStatus::Active->value)->whereIn('id', $reports->keys())->get();
        $byCluster = [];

        BenchmarkSnapshot::query()->where('period', $period)->whereNotIn('store_id', $stores->pluck('id'))->forceDelete();

        foreach ($stores as $store) {
            $metrics = $this->metrics($store, $reports->get($store->id), $period);
            $snapshot = BenchmarkSnapshot::query()->where('store_id', $store->id)->where('period', $period)->first();

            if ($snapshot === null) {
                BenchmarkSnapshot::query()->create(['store_id' => $store->id, 'period' => $period, 'cluster' => $store->size_cluster->value, 'metrics' => $metrics]);
            } else {
                $snapshot->update(['cluster' => $store->size_cluster->value, 'metrics' => $metrics]);
            }

            foreach ([$store->size_cluster->value, self::ALL] as $cluster) {
                foreach ($metrics as $code => $value) {
                    if ($value !== null) {
                        $byCluster[$cluster][$code][] = $value;
                    }
                }
            }
        }

        BenchmarkNetworkStat::query()->where('period', $period)->forceDelete();
        $count = 0;

        foreach ($byCluster as $cluster => $metrics) {
            foreach ($metrics as $code => $values) {
                BenchmarkNetworkStat::query()->create(['period' => $period, 'cluster' => $cluster, 'metric' => $code, ...Distribution::summarize($values)]);
                $count++;
            }
        }

        return ['stores' => $stores->count(), 'stats' => $count];
    }

    /**
     * KPIs of a store for the month; a figure that cannot be computed (no tickets, no prior-year month) is null.
     *
     * @return array<string, string|null>
     */
    public function metrics(FranchiseStore $store, SellOutReport $report, string $period): array
    {
        $prior = SellOutReport::query()->where('store_id', $store->id)->where('period', BillingPeriod::sameMonthLastYear($period))->first();
        $openedBeforePrior = $store->opened_on !== null && $store->opened_on->startOfMonth()->lessThanOrEqualTo(BillingPeriod::bounds(BillingPeriod::sameMonthLastYear($period))[0]);

        return [
            'net_sell_out' => Dec::round($report->net_sales),
            'avg_ticket' => $report->tickets_count > 0 ? Dec::round(Dec::div($report->net_sales, $report->tickets_count)) : null,
            'conversion_rate' => $report->visitors_count > 0 ? Dec::round(Dec::mul(Dec::div($report->tickets_count, $report->visitors_count), 100)) : null,
            'stock_rotation' => Dec::cmp($report->average_inventory_value, 0) > 0 ? Dec::round(Dec::div(Dec::mul($report->cost_of_goods_sold, 12), $report->average_inventory_value)) : null,
            'lfl_growth' => $prior !== null && $openedBeforePrior && Dec::cmp($prior->net_sales, 0) > 0
                ? Dec::round(Dec::mul(Dec::div(Dec::sub($report->net_sales, $prior->net_sales), $prior->net_sales), 100))
                : null,
        ];
    }

    /**
     * What a franchisee may see: its KPIs against the anonymous aggregates of its cluster and of the network.
     *
     * @return array<string, mixed>
     */
    public function comparison(FranchiseStore $store, string $period): array
    {
        BillingPeriod::assert($period);
        $snapshot = BenchmarkSnapshot::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('period', $period)->first();

        if ($snapshot === null) {
            throw new FranchiseNetworkException('benchmark_not_available', 404);
        }

        $stats = BenchmarkNetworkStat::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $store->tenant_id)->where('period', $period)->whereIn('cluster', [$snapshot->cluster->value, self::ALL])->get();
        $minPeers = (int) config('franchise_network.benchmark.min_peers');
        $clusterSize = (int) ($stats->where('cluster', $snapshot->cluster->value)->max('sample_size') ?? 0);
        $clusterHidden = $clusterSize < $minPeers;
        $dimensions = BenchmarkDimension::query()->withoutGlobalScope(TenantScope::class)->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $store->tenant_id))->orderBy('sort_order')->get();

        $rows = [];

        foreach ($dimensions as $dimension) {
            $own = $snapshot->metrics[$dimension->code] ?? null;
            $network = $stats->where('cluster', self::ALL)->firstWhere('metric', $dimension->code);
            $cluster = $clusterHidden ? null : $stats->where('cluster', $snapshot->cluster->value)->firstWhere('metric', $dimension->code);

            $rows[] = [
                'dimension' => $dimension,
                'own' => $own,
                'network' => $this->aggregate($network),
                'cluster' => $this->aggregate($cluster),
                'position' => $own === null || $network === null ? null : $this->position($own, $cluster ?? $network, (bool) $dimension->higher_is_better),
            ];
        }

        return ['period' => $period, 'cluster' => $snapshot->cluster->value, 'cluster_hidden' => $clusterHidden, 'rows' => $rows];
    }

    /**
     * Latest month with a snapshot for the store.
     */
    public function latestPeriod(FranchiseStore $store): ?string
    {
        return BenchmarkSnapshot::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->max('period');
    }

    /**
     * @return array{average: string|null, median: string|null, p75: string|null, sample_size: int}|null
     */
    private function aggregate(?BenchmarkNetworkStat $stat): ?array
    {
        return $stat === null ? null : ['average' => $stat->average, 'median' => $stat->median, 'p75' => $stat->p75, 'sample_size' => $stat->sample_size];
    }

    private function position(string $own, BenchmarkNetworkStat $reference, bool $higherIsBetter): string
    {
        $direction = $higherIsBetter ? 1 : -1;
        $top = $reference->p75 === null ? null : Dec::cmp($own, $reference->p75) * $direction;
        $median = $reference->median === null ? null : Dec::cmp($own, $reference->median) * $direction;

        return match (true) {
            $top !== null && $top >= 0 => 'top_quartile',
            $median !== null && $median >= 0 => 'above_median',
            default => 'below_median',
        };
    }
}
