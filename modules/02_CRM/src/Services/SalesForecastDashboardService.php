<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\CRM\Models\LossReason;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\SalesQuota;
use Modules\CRM\Support\WeightedValue;

/**
 * Sales forecasting and quota tracking: monthly / quarterly buckets by expected close date (actual close date for
 * closed deals), split into Pipeline, Best Case and Commit alongside Closed Won / Lost, compared with the quotas
 * assigned per sales rep and period, plus the retrospective loss analysis.
 *
 * Forecast categories are exclusive in the buckets; the roll-ups follow the usual convention:
 * commit forecast = closed won + commit, best case forecast = closed won + commit + best case.
 */
final readonly class SalesForecastDashboardService
{
    /**
     * @param  array{granularity?: string|null, year?: int|null, quarter?: int|null, month?: int|null, pipeline_id?: string|null, owner_user_id?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function dashboard(string $tenantId, array $filters): array
    {
        $granularity = ($filters['granularity'] ?? 'month') === 'quarter' ? 'quarter' : 'month';
        $year = (int) ($filters['year'] ?? Carbon::now()->year);
        $ownerId = $filters['owner_user_id'] ?? null;

        [$periodStart, $periodEnd] = $this->period($year, $filters['quarter'] ?? null, $filters['month'] ?? null);
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $deals = $this->deals($tenantId, $yearStart, $yearEnd, $filters['pipeline_id'] ?? null, $ownerId);
        $quotas = SalesQuota::where('tenant_id', $tenantId)
            ->when($ownerId !== null, fn ($q) => $q->where('user_id', $ownerId))
            ->whereDate('period_start', '<=', $yearEnd->toDateString())
            ->whereDate('period_end', '>=', $yearStart->toDateString())
            ->get();

        $buckets = [];
        foreach ($this->buckets($year, $granularity) as $bucket) {
            $buckets[] = $this->summarize($bucket, $deals, $quotas);
        }

        $periodBucket = ['key' => 'period', 'label' => 'period', 'start' => $periodStart, 'end' => $periodEnd];
        $totals = $this->summarize($periodBucket, $deals, $quotas);

        return [
            'granularity' => $granularity,
            'year' => $year,
            'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
            'buckets' => $buckets,
            'totals' => $this->publicFigures($totals),
            'reps' => $this->reps($tenantId, $periodStart, $periodEnd, $deals, $quotas),
        ];
    }

    /**
     * @param  array{from?: string|null, to?: string|null, pipeline_id?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function lossAnalysis(string $tenantId, array $filters): array
    {
        $lost = Opportunity::where('tenant_id', $tenantId)
            ->where('status', 'lost')
            ->when(! empty($filters['pipeline_id']), fn ($q) => $q->where('pipeline_id', $filters['pipeline_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('actual_close_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('actual_close_date', '<=', $filters['to']))
            ->get(['id', 'amount', 'loss_reason_code', 'win_loss_reason', 'competitor']);

        $catalog = LossReason::where('tenant_id', $tenantId)->get()->keyBy('code');

        $byReason = [];
        $byCompetitor = [];
        $total = '0.00';

        foreach ($lost as $deal) {
            $amount = WeightedValue::round2(WeightedValue::decimal((string) $deal->amount));
            $total = bcadd($total, $amount, 2);

            $code = $deal->loss_reason_code ?? 'unspecified';
            $label = $deal->loss_reason_code !== null && $catalog->has($code) ? $catalog[$code]->label() : (string) ($deal->win_loss_reason ?? $code);
            $byReason[$code] ??= ['code' => $code, 'label' => $label, 'count' => 0, 'amount' => '0.00'];
            $byReason[$code]['count']++;
            $byReason[$code]['amount'] = bcadd($byReason[$code]['amount'], $amount, 2);

            $competitor = trim((string) $deal->competitor) !== '' ? trim((string) $deal->competitor) : 'unspecified';
            $key = mb_strtolower($competitor);
            $byCompetitor[$key] ??= ['competitor' => $competitor, 'count' => 0, 'amount' => '0.00'];
            $byCompetitor[$key]['count']++;
            $byCompetitor[$key]['amount'] = bcadd($byCompetitor[$key]['amount'], $amount, 2);
        }

        $sort = static function (array $rows): array {
            usort($rows, static fn (array $a, array $b): int => [$b['count'], $b['amount']] <=> [$a['count'], $a['amount']]);

            return $rows;
        };

        return [
            'total_lost' => ['count' => $lost->count(), 'amount' => $total],
            'by_reason' => $sort($byReason),
            'by_competitor' => $sort($byCompetitor),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(int $year, ?int $quarter, ?int $month): array
    {
        if ($month !== null && $month >= 1 && $month <= 12) {
            $start = Carbon::create($year, $month, 1)->startOfDay();

            return [$start, $start->copy()->endOfMonth()];
        }

        if ($quarter !== null && $quarter >= 1 && $quarter <= 4) {
            $start = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();

            return [$start, $start->copy()->addMonths(2)->endOfMonth()];
        }

        return [Carbon::create($year, 1, 1)->startOfDay(), Carbon::create($year, 12, 31)->endOfDay()];
    }

    /**
     * @return list<array{key: string, label: string, start: Carbon, end: Carbon}>
     */
    private function buckets(int $year, string $granularity): array
    {
        $buckets = [];

        if ($granularity === 'quarter') {
            for ($q = 1; $q <= 4; $q++) {
                $start = Carbon::create($year, ($q - 1) * 3 + 1, 1)->startOfDay();
                $buckets[] = ['key' => sprintf('%d-Q%d', $year, $q), 'label' => 'Q'.$q, 'start' => $start, 'end' => $start->copy()->addMonths(2)->endOfMonth()];
            }

            return $buckets;
        }

        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create($year, $m, 1)->startOfDay();
            $buckets[] = ['key' => sprintf('%d-%02d', $year, $m), 'label' => $start->format('m'), 'start' => $start, 'end' => $start->copy()->endOfMonth()];
        }

        return $buckets;
    }

    /**
     * Deals of the year with the date that places them in a bucket: the close date of won/lost deals, the expected
     * close date of the open ones.
     *
     * @return list<array{date: Carbon, owner: string|null, status: string, category: string, amount: string, weighted: string}>
     */
    private function deals(string $tenantId, Carbon $from, Carbon $to, ?string $pipelineId, ?string $ownerId): array
    {
        $rows = Opportunity::where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->when($pipelineId !== null, fn ($q) => $q->where('pipeline_id', $pipelineId))
            ->when($ownerId !== null, fn ($q) => $q->where('owner_user_id', $ownerId))
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('expected_close_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('actual_close_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->get(['owner_user_id', 'status', 'forecast_category', 'amount', 'probability', 'expected_close_date', 'actual_close_date']);

        $deals = [];
        foreach ($rows as $row) {
            $closed = in_array($row->status, ['won', 'lost'], true);
            $date = ($closed ? ($row->actual_close_date ?? $row->expected_close_date) : $row->expected_close_date)?->copy();
            if ($date === null || $date->lt($from) || $date->gt($to)) {
                continue;
            }

            $deals[] = [
                'date' => $date,
                'owner' => $row->owner_user_id !== null ? (string) $row->owner_user_id : null,
                'status' => (string) $row->status,
                'category' => (string) $row->forecast_category,
                'amount' => WeightedValue::round2(WeightedValue::decimal((string) $row->amount)),
                'weighted' => WeightedValue::compute((string) $row->amount, (string) $row->probability),
            ];
        }

        return $deals;
    }

    /**
     * @param  array{key: string, label: string, start: Carbon, end: Carbon}  $bucket
     * @param  list<array<string, mixed>>  $deals
     * @param  iterable<SalesQuota>  $quotas
     * @return array<string, mixed>
     */
    private function summarize(array $bucket, array $deals, iterable $quotas, ?string $owner = null, bool $filterOwner = false): array
    {
        $figures = ['pipeline' => '0.00', 'best_case' => '0.00', 'commit' => '0.00', 'closed_won' => '0.00', 'closed_lost' => '0.00', 'weighted_open' => '0.00', 'open_count' => 0, 'won_count' => 0, 'lost_count' => 0];

        foreach ($deals as $deal) {
            if ($deal['date']->lt($bucket['start']) || $deal['date']->gt($bucket['end'])) {
                continue;
            }
            if ($filterOwner && $deal['owner'] !== $owner) {
                continue;
            }

            if ($deal['status'] === 'won') {
                $figures['closed_won'] = bcadd($figures['closed_won'], $deal['amount'], 2);
                $figures['won_count']++;
            } elseif ($deal['status'] === 'lost') {
                $figures['closed_lost'] = bcadd($figures['closed_lost'], $deal['amount'], 2);
                $figures['lost_count']++;
            } elseif ($deal['category'] !== 'omitted') {
                // Open deals flagged as omitted stay out of the forecast.
                $category = in_array($deal['category'], ['best_case', 'commit'], true) ? $deal['category'] : 'pipeline';
                $figures[$category] = bcadd($figures[$category], $deal['amount'], 2);
                $figures['weighted_open'] = bcadd($figures['weighted_open'], $deal['weighted'], 2);
                $figures['open_count']++;
            }
        }

        $quota = '0.00';
        foreach ($quotas as $row) {
            if ($filterOwner && (string) $row->user_id !== $owner) {
                continue;
            }
            $quota = bcadd($quota, $this->prorate($row, $bucket['start'], $bucket['end']), 2);
        }

        $figures['quota'] = $quota;
        $figures['commit_forecast'] = bcadd($figures['closed_won'], $figures['commit'], 2);
        $figures['best_case_forecast'] = bcadd($figures['commit_forecast'], $figures['best_case'], 2);
        $figures['weighted_forecast'] = bcadd($figures['closed_won'], $figures['weighted_open'], 2);

        return ['key' => $bucket['key'], 'label' => $bucket['label'], 'start' => $bucket['start']->toDateString(), 'end' => $bucket['end']->toDateString(), ...$figures];
    }

    /**
     * Share of a quota that falls inside [start, end], prorated by days so a quarterly quota splits across months
     * and monthly quotas add up in a quarter.
     */
    private function prorate(SalesQuota $quota, Carbon $start, Carbon $end): string
    {
        $quotaStart = $quota->period_start->copy()->startOfDay();
        $quotaEnd = $quota->period_end->copy()->endOfDay();
        $overlapStart = $quotaStart->gt($start) ? $quotaStart : $start;
        $overlapEnd = $quotaEnd->lt($end) ? $quotaEnd : $end;

        if ($overlapStart->gt($overlapEnd)) {
            return '0.00';
        }

        $quotaDays = max(1, (int) $quotaStart->diffInDays($quotaEnd->copy()->startOfDay()) + 1);
        $overlapDays = (int) $overlapStart->copy()->startOfDay()->diffInDays($overlapEnd->copy()->startOfDay()) + 1;

        return WeightedValue::round2(bcdiv(bcmul(WeightedValue::decimal((string) $quota->target_amount), (string) $overlapDays, 6), (string) $quotaDays, 6));
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function publicFigures(array $summary): array
    {
        $quota = (string) $summary['quota'];
        $summary['attainment_percent'] = $this->percent((string) $summary['closed_won'], $quota);
        $summary['commit_attainment_percent'] = $this->percent((string) $summary['commit_forecast'], $quota);
        $summary['best_case_attainment_percent'] = $this->percent((string) $summary['best_case_forecast'], $quota);
        $summary['weighted_attainment_percent'] = $this->percent((string) $summary['weighted_forecast'], $quota);
        $summary['gap_to_quota'] = bccomp($quota, (string) $summary['weighted_forecast'], 2) > 0 ? bcsub($quota, (string) $summary['weighted_forecast'], 2) : '0.00';

        return $summary;
    }

    private function percent(string $value, string $quota): string
    {
        return bccomp($quota, '0', 2) > 0 ? bcdiv(bcmul($value, '100', 4), $quota, 2) : '0.00';
    }

    /**
     * Per sales rep: quota of the period against closed, commit, best case and weighted forecast.
     *
     * @param  list<array<string, mixed>>  $deals
     * @param  iterable<SalesQuota>  $quotas
     * @return list<array<string, mixed>>
     */
    private function reps(string $tenantId, Carbon $start, Carbon $end, array $deals, iterable $quotas): array
    {
        $owners = [];
        foreach ($deals as $deal) {
            $owners[(string) ($deal['owner'] ?? '')] = $deal['owner'];
        }
        foreach ($quotas as $quota) {
            $owners[(string) $quota->user_id] = (string) $quota->user_id;
        }

        $names = User::whereIn('id', array_filter(array_values($owners)))->pluck('name', 'id');
        $bucket = ['key' => 'period', 'label' => 'period', 'start' => $start, 'end' => $end];
        $reps = [];

        foreach ($owners as $owner) {
            $summary = $this->publicFigures($this->summarize($bucket, $deals, $quotas, $owner, true));
            $reps[] = ['user_id' => $owner, 'name' => $owner !== null ? (string) ($names[$owner] ?? '') : null, ...$summary];
        }

        usort($reps, static fn (array $a, array $b): int => bccomp((string) $b['closed_won'], (string) $a['closed_won'], 2));

        return $reps;
    }
}
