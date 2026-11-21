<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\Services;

/**
 * Rule-based and z-score anomaly detection on the executive figures. Alerts carry a stable `code` and the numbers as
 * `context`: the clients translate the code, no text is produced here.
 */
final class ExecutiveAnomalyDetector
{
    /**
     * @param  array{concentration_threshold_pct?: float, margin_erosion_points?: float, dso_spike_days?: float, budget_overrun_pct?: float, zscore_threshold?: float, zscore_min_points?: int}  $thresholds
     */
    public function __construct(private readonly array $thresholds = []) {}

    /**
     * @param  array{gross_margin_pct: float, prior_gross_margin_pct: float|null, dso_days: float, prior_dso_days: float|null, customer_revenue: array<string, array{label: string, revenue: float}>, budget_lines: array<string, array{budget: float, actual: float, is_cost: bool}>, series: array<string, list<float>>}  $facts
     * @return list<array{code: string, severity: string, metric: string, value: float, threshold: float, context: array<string, mixed>}>
     */
    public function detect(array $facts): array
    {
        $alerts = [
            ...$this->marginErosion($facts),
            ...$this->dsoSpike($facts),
            ...$this->concentration($facts),
            ...$this->budgetOverruns($facts),
            ...$this->statisticalOutliers($facts['series']),
        ];

        $rank = ['CRITICAL' => 0, 'WARNING' => 1, 'INFO' => 2];
        usort($alerts, static fn (array $a, array $b): int => [$rank[$a['severity']], $a['code'], $a['metric']] <=> [$rank[$b['severity']], $b['code'], $b['metric']]);

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{code: string, severity: string, metric: string, value: float, threshold: float, context: array<string, mixed>}>
     */
    private function marginErosion(array $facts): array
    {
        $limit = (float) ($this->thresholds['margin_erosion_points'] ?? 3.0);
        $prior = $facts['prior_gross_margin_pct'];

        if ($prior === null) {
            return [];
        }

        $drop = $prior - $facts['gross_margin_pct'];

        return $drop >= $limit
            ? [$this->alert('margin_erosion', $drop >= $limit * 2 ? 'CRITICAL' : 'WARNING', 'gross_margin_pct', round($drop, 2), $limit, ['current' => $facts['gross_margin_pct'], 'prior' => $prior])]
            : [];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{code: string, severity: string, metric: string, value: float, threshold: float, context: array<string, mixed>}>
     */
    private function dsoSpike(array $facts): array
    {
        $limit = (float) ($this->thresholds['dso_spike_days'] ?? 7.0);
        $prior = $facts['prior_dso_days'];

        if ($prior === null) {
            return [];
        }

        $rise = $facts['dso_days'] - $prior;

        return $rise >= $limit
            ? [$this->alert('dso_spike', $rise >= $limit * 2 ? 'CRITICAL' : 'WARNING', 'dso_days', round($rise, 2), $limit, ['current' => $facts['dso_days'], 'prior' => $prior])]
            : [];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{code: string, severity: string, metric: string, value: float, threshold: float, context: array<string, mixed>}>
     */
    private function concentration(array $facts): array
    {
        $limit = (float) ($this->thresholds['concentration_threshold_pct'] ?? 25.0);
        $total = array_sum(array_column($facts['customer_revenue'], 'revenue'));
        $alerts = [];

        if ($total <= 0.0) {
            return [];
        }

        foreach ($facts['customer_revenue'] as $key => $customer) {
            $share = $customer['revenue'] / $total * 100.0;

            if ($share > $limit) {
                $alerts[] = $this->alert('customer_concentration', $share > $limit * 1.6 ? 'CRITICAL' : 'WARNING', 'customer_share_pct', round($share, 2), $limit, ['counterparty_id' => $key, 'label' => $customer['label']]);
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{code: string, severity: string, metric: string, value: float, threshold: float, context: array<string, mixed>}>
     */
    private function budgetOverruns(array $facts): array
    {
        $limit = (float) ($this->thresholds['budget_overrun_pct'] ?? 10.0);
        $alerts = [];

        foreach ($facts['budget_lines'] as $key => $line) {
            if ($line['budget'] <= 0.0 || ! $line['is_cost']) {
                continue;
            }

            $overrun = ($line['actual'] - $line['budget']) / $line['budget'] * 100.0;

            if ($overrun > $limit) {
                $alerts[] = $this->alert('budget_overrun', $overrun > $limit * 2 ? 'CRITICAL' : 'WARNING', $key, round($overrun, 2), $limit, ['budget' => $line['budget'], 'actual' => $line['actual']]);
            }
        }

        return $alerts;
    }

    /**
     * Flags the latest point of a series that deviates more than the threshold standard deviations from its history.
     *
     * @param  array<string, list<float>>  $series
     * @return list<array{code: string, severity: string, metric: string, value: float, threshold: float, context: array<string, mixed>}>
     */
    private function statisticalOutliers(array $series): array
    {
        $threshold = (float) ($this->thresholds['zscore_threshold'] ?? 2.0);
        $minPoints = (int) ($this->thresholds['zscore_min_points'] ?? 6);
        $alerts = [];

        foreach ($series as $metric => $values) {
            if (count($values) < $minPoints) {
                continue;
            }

            $latest = array_pop($values);
            $mean = array_sum($values) / count($values);
            $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / count($values);
            $deviation = sqrt($variance);

            if ($deviation <= 0.0) {
                continue;
            }

            $z = ($latest - $mean) / $deviation;

            if (abs($z) >= $threshold) {
                $alerts[] = $this->alert('statistical_outlier', abs($z) >= $threshold * 1.5 ? 'WARNING' : 'INFO', (string) $metric, round($z, 2), $threshold, ['latest' => $latest, 'mean' => round($mean, 4)]);
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, severity: string, metric: string, value: float, threshold: float, context: array<string, mixed>}
     */
    private function alert(string $code, string $severity, string $metric, float $value, float $threshold, array $context): array
    {
        return ['code' => $code, 'severity' => $severity, 'metric' => $metric, 'value' => $value, 'threshold' => $threshold, 'context' => $context];
    }
}
