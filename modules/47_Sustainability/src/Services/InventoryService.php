<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Support\Decimal;

/**
 * The GHG inventory of a reporting period: CO2e by scope and category, the share of estimated data, the intensity per
 * revenue and per employee, and the change against the previous period. Sums are exact (decimal strings), never
 * aggregated by the database, because SQLite adds decimal columns as floats.
 */
final class InventoryService
{
    /**
     * @return array<string, mixed>
     */
    public function report(EsgReportingPeriod $period): array
    {
        $tenantId = (string) $period->tenant_id;
        $current = $this->aggregate($tenantId, $period->starts_on->toDateString(), $period->ends_on->toDateString());
        $previousPeriod = EsgReportingPeriod::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('ends_on', '<', $period->starts_on->toDateString())
            ->orderByDesc('ends_on')
            ->first();

        $previous = $previousPeriod === null ? null : $this->aggregate($tenantId, $previousPeriod->starts_on->toDateString(), $previousPeriod->ends_on->toDateString());

        return [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'status' => $period->status,
            ],
            'totals' => $this->totalsOf($current),
            'scopes' => $this->scopesOf($current),
            'data_quality' => $this->qualityOf($current),
            'intensity' => $this->intensityOf($current['total'], $period),
            'previous' => $previousPeriod === null || $previous === null ? null : [
                'period_id' => $previousPeriod->id,
                'period_name' => $previousPeriod->name,
                'totals' => $this->totalsOf($previous),
                'delta' => $this->deltaOf($current, $previous),
            ],
        ];
    }

    /**
     * Emissions of one calendar year for a target scope (scope_1, scope_2, scope_3 or total), in kg CO2e.
     */
    public function emissionsOfYear(string $tenantId, int $year, string $scope): string
    {
        $aggregate = $this->aggregate($tenantId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));

        return Decimal::round($scope === 'total' ? $aggregate['total'] : $aggregate['scopes'][(int) substr($scope, -1)]['total'], 4);
    }

    /**
     * @return array{total: string, records: int, estimated: string, estimated_records: int, scopes: array<int, array{total: string, categories: array<string, array{total: string, records: int}>}>}
     */
    private function aggregate(string $tenantId, string $from, string $to): array
    {
        $aggregate = [
            'total' => '0',
            'records' => 0,
            'estimated' => '0',
            'estimated_records' => 0,
            'scopes' => [1 => ['total' => '0', 'categories' => []], 2 => ['total' => '0', 'categories' => []], 3 => ['total' => '0', 'categories' => []]],
        ];

        $rows = EsgActivityRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('activity_date', '>=', $from)
            ->whereDate('activity_date', '<=', $to)
            ->select(['scope', 'category', 'data_quality', 'co2e_kg'])
            ->cursor();

        foreach ($rows as $row) {
            $scope = (int) $row->scope;
            $category = (string) $row->category;
            $kg = (string) $row->co2e_kg;

            $aggregate['total'] = Decimal::add($aggregate['total'], $kg);
            $aggregate['records']++;
            $aggregate['scopes'][$scope]['total'] = Decimal::add($aggregate['scopes'][$scope]['total'], $kg);
            $bucket = $aggregate['scopes'][$scope]['categories'][$category] ?? ['total' => '0', 'records' => 0];
            $aggregate['scopes'][$scope]['categories'][$category] = ['total' => Decimal::add($bucket['total'], $kg), 'records' => $bucket['records'] + 1];

            if ($row->data_quality === 'estimated') {
                $aggregate['estimated'] = Decimal::add($aggregate['estimated'], $kg);
                $aggregate['estimated_records']++;
            }
        }

        return $aggregate;
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return array<string, string>
     */
    private function totalsOf(array $aggregate): array
    {
        return [
            'scope_1_kg' => Decimal::round($aggregate['scopes'][1]['total'], 4),
            'scope_2_kg' => Decimal::round($aggregate['scopes'][2]['total'], 4),
            'scope_3_kg' => Decimal::round($aggregate['scopes'][3]['total'], 4),
            'total_kg' => Decimal::round($aggregate['total'], 4),
            'total_tco2e' => Decimal::round(Decimal::div($aggregate['total'], '1000'), 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return list<array<string, mixed>>
     */
    private function scopesOf(array $aggregate): array
    {
        $scopes = [];

        foreach ($aggregate['scopes'] as $scope => $data) {
            $categories = [];
            foreach ($data['categories'] as $category => $bucket) {
                $categories[] = [
                    'category' => $category,
                    'co2e_kg' => Decimal::round($bucket['total'], 4),
                    'share_percent' => $this->percentOf($bucket['total'], $aggregate['total']),
                    'records' => $bucket['records'],
                ];
            }

            usort($categories, static fn (array $left, array $right): int => Decimal::compare($right['co2e_kg'], $left['co2e_kg']));

            $scopes[] = [
                'scope' => $scope,
                'co2e_kg' => Decimal::round($data['total'], 4),
                'tco2e' => Decimal::round(Decimal::div($data['total'], '1000'), 3),
                'share_percent' => $this->percentOf($data['total'], $aggregate['total']),
                'categories' => $categories,
            ];
        }

        return $scopes;
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return array<string, mixed>
     */
    private function qualityOf(array $aggregate): array
    {
        return [
            'records' => $aggregate['records'],
            'estimated_records' => $aggregate['estimated_records'],
            'estimated_kg' => Decimal::round($aggregate['estimated'], 4),
            'estimated_share_percent' => $this->percentOf($aggregate['estimated'], $aggregate['total']),
        ];
    }

    /**
     * Emission intensity as ESRS E1-6 reports it: tCO2e per million of net revenue, and tCO2e per employee (FTE).
     *
     * @return array<string, mixed>
     */
    private function intensityOf(string $totalKg, EsgReportingPeriod $period): array
    {
        $tonnes = Decimal::div($totalKg, '1000');
        $revenue = $period->revenue;
        $fte = $period->employees_fte;

        return [
            'currency' => $period->currency,
            'tco2e_per_million_revenue' => $revenue !== null && Decimal::isPositive($revenue) ? Decimal::round(Decimal::div($tonnes, Decimal::div($revenue, '1000000')), 3) : null,
            'tco2e_per_fte' => $fte !== null && Decimal::isPositive($fte) ? Decimal::round(Decimal::div($tonnes, $fte), 3) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array{absolute_kg: string, percent: string|null}>
     */
    private function deltaOf(array $current, array $previous): array
    {
        $pairs = [
            'scope_1' => [$current['scopes'][1]['total'], $previous['scopes'][1]['total']],
            'scope_2' => [$current['scopes'][2]['total'], $previous['scopes'][2]['total']],
            'scope_3' => [$current['scopes'][3]['total'], $previous['scopes'][3]['total']],
            'total' => [$current['total'], $previous['total']],
        ];
        $delta = [];

        foreach ($pairs as $key => [$now, $before]) {
            $delta[$key] = [
                'absolute_kg' => Decimal::round(Decimal::sub($now, $before), 4),
                'percent' => Decimal::isPositive($before) ? Decimal::round(Decimal::div(Decimal::mul(Decimal::sub($now, $before), '100'), $before), 2) : null,
            ];
        }

        return $delta;
    }

    private function percentOf(string $part, string $whole): string
    {
        return Decimal::isPositive($whole) ? Decimal::round(Decimal::div(Decimal::mul($part, '100'), $whole), 2) : '0.00';
    }
}
