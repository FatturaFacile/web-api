<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Ccii;

use Modules\CrisisMonitoring\Domain\Enums\TrafficLight;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The seven early-warning indicators of the CNDCEC for the CCII (D.Lgs. 14/2019) and Art. 2086 c.c.: the two primary triggers (net equity
 * negative or below the legal minimum, DSCR over six months below 1.0) and the five sectoral ratios compared with the ATECO thresholds.
 * Ratios are in percent. Pure: figures and thresholds come in, the indicators come out.
 */
final readonly class EarlyWarningEvaluator
{
    /** Share of the threshold, in points, within which a ratio turns yellow before it breaches. */
    private const string WarningMargin = '0.20';

    /**
     * @param  array{net_equity: string, legal_minimum_capital: string|null, financial_expenses: string, revenue: string, total_debt: string, total_assets: string, current_assets: string, current_liabilities: string, cash_flow: string, tax_social_debt: string}  $figures
     * @param  array{value: string|null, status: string, applicable: bool}  $dscr6m  the treasury DSCR over six months
     * @param  array{financial_charges_revenue_max: string, equity_total_debt_min: string, liquidity_ratio_min: string, cash_flow_assets_min: string, tax_social_debt_assets_max: string}|null  $thresholds  null when the sector is unknown
     * @return array{overall_status: string, primary_triggers: list<string>, indicators: list<array<string, mixed>>}
     */
    public function evaluate(array $figures, array $dscr6m, ?array $thresholds): array
    {
        $indicators = [$this->netEquity($figures), $this->dscr($dscr6m)];

        $indicators[] = $this->ratio('financial_charges_revenue', $figures['financial_expenses'], $figures['revenue'], $thresholds['financial_charges_revenue_max'] ?? null, 'max');
        $indicators[] = $this->ratio('equity_total_debt', $figures['net_equity'], $figures['total_debt'], $thresholds['equity_total_debt_min'] ?? null, 'min');
        $indicators[] = $this->ratio('liquidity_ratio', $figures['current_assets'], $figures['current_liabilities'], $thresholds['liquidity_ratio_min'] ?? null, 'min');
        $indicators[] = $this->ratio('cash_flow_assets', $figures['cash_flow'], $figures['total_assets'], $thresholds['cash_flow_assets_min'] ?? null, 'min');
        $indicators[] = $this->ratio('tax_social_debt_assets', $figures['tax_social_debt'], $figures['total_assets'], $thresholds['tax_social_debt_assets_max'] ?? null, 'max');

        $primary = [];

        foreach (array_slice($indicators, 0, 2) as $indicator) {
            if ($indicator['status'] === TrafficLight::Red->value) {
                $primary[] = $indicator['code'];
            }
        }

        $ratios = array_slice($indicators, 2);
        $breached = array_filter($ratios, static fn (array $indicator): bool => $indicator['status'] === TrafficLight::Red->value);
        $overall = match (true) {
            $primary !== [] || count($breached) === count($ratios) => TrafficLight::Red,
            default => TrafficLight::worst(array_column($ratios, 'status')),
        };

        return ['overall_status' => $overall->value, 'primary_triggers' => $primary, 'indicators' => $indicators];
    }

    /**
     * @param  array{net_equity: string, legal_minimum_capital: string|null}  $figures
     * @return array<string, mixed>
     */
    private function netEquity(array $figures): array
    {
        $threshold = $figures['legal_minimum_capital'] !== null && Decimal::isPositive($figures['legal_minimum_capital']) ? $figures['legal_minimum_capital'] : '0';
        $status = Decimal::compare($figures['net_equity'], $threshold) < 0 ? TrafficLight::Red : TrafficLight::Green;

        return [
            'code' => 'net_equity',
            'label_key' => 'crisisApi.indicator.net_equity',
            'description_key' => 'crisisApi.indicator.net_equity.description',
            'kind' => 'primary',
            'unit' => 'amount',
            'actual' => Decimal::round2($figures['net_equity']),
            'threshold' => Decimal::round2($threshold),
            'comparison' => 'min',
            'variance' => Decimal::round2(Decimal::sub($figures['net_equity'], $threshold)),
            'status' => $status->value,
        ];
    }

    /**
     * @param  array{value: string|null, status: string, applicable: bool}  $dscr
     * @return array<string, mixed>
     */
    private function dscr(array $dscr): array
    {
        return [
            'code' => 'dscr_6m',
            'label_key' => 'crisisApi.indicator.dscr_6m',
            'description_key' => 'crisisApi.indicator.dscr_6m.description',
            'kind' => 'primary',
            'unit' => 'ratio',
            'actual' => $dscr['value'],
            'threshold' => '1.00',
            'comparison' => 'min',
            'variance' => $dscr['value'] === null ? null : Decimal::round2(Decimal::sub($dscr['value'], '1.00')),
            'status' => $dscr['applicable'] ? $dscr['status'] : TrafficLight::Green->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ratio(string $code, string $numerator, string $denominator, ?string $threshold, string $comparison): array
    {
        $actual = Decimal::isZero($denominator) ? null : Decimal::round2(Decimal::mul(Decimal::div($numerator, $denominator), '100'));
        $status = TrafficLight::Green;

        if ($actual !== null && $threshold !== null) {
            $status = $this->classify($actual, $threshold, $comparison);
        }

        return [
            'code' => $code,
            'label_key' => 'crisisApi.indicator.'.$code,
            'description_key' => 'crisisApi.indicator.'.$code.'.description',
            'kind' => 'ratio',
            'unit' => 'percent',
            'actual' => $actual,
            'threshold' => $threshold === null ? null : Decimal::round2($threshold),
            'comparison' => $comparison,
            'variance' => $actual === null || $threshold === null ? null : Decimal::round2(Decimal::sub($actual, $threshold)),
            'status' => $status->value,
        ];
    }

    private function classify(string $actual, string $threshold, string $comparison): TrafficLight
    {
        $margin = Decimal::mul(Decimal::abs($threshold), self::WarningMargin);
        $margin = Decimal::compare($margin, '1') < 0 ? '1' : $margin;

        if ($comparison === 'max') {
            if (Decimal::compare($actual, $threshold) >= 0) {
                return TrafficLight::Red;
            }

            return Decimal::compare($actual, Decimal::sub($threshold, $margin)) >= 0 ? TrafficLight::Yellow : TrafficLight::Green;
        }

        if (Decimal::compare($actual, $threshold) <= 0) {
            return TrafficLight::Red;
        }

        return Decimal::compare($actual, Decimal::add($threshold, $margin)) <= 0 ? TrafficLight::Yellow : TrafficLight::Green;
    }
}
