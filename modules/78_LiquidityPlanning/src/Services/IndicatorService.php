<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Models\LiquiditySettings;
use Modules\LiquidityPlanning\Support\Flow;

/**
 * The crisis early-warning indicators of CO 725 / 725a / 725b (Switzerland) and Art. 2086, 2446, 2447 c.c. with the CCII (Italy):
 *
 * - DSCR over the horizon: operating cash flow (inflows less operating outflows, capex and financing excluded) divided by the
 *   debt service (principal and interest); below the critical threshold (1.0) the debt cannot be served from operations;
 * - capital: net equity against the share capital (loss of half of the capital, below the legal minimum, over-indebtedness);
 * - PFN (net financial position = financial debt less cash) and PFN / EBITDA;
 * - liquidity: first negative day, runway and the minimum liquidity.
 */
final readonly class IndicatorService
{
    public function __construct(private LedgerPositionReader $ledger) {}

    /**
     * @param  list<Flow>  $flows  the flows of the horizon after the scenario
     * @param  array<string, mixed>  $projection  see ProjectionBuilder::build()
     * @param  array{net_equity: string, financial_debt: string, ebitda: string}  $position
     * @return array{dscr: array<string, mixed>, capital: array<string, mixed>, pfn: array<string, mixed>, liquidity: array<string, mixed>}
     */
    public function compute(array $flows, array $projection, string $openingCash, CarbonImmutable $start, int $horizonDays, LiquiditySettings $settings, array $position, string $ebitdaDelta = '0'): array
    {
        $dscr = $this->dscr($flows, $start, $horizonDays, $settings);

        return [
            'dscr' => $dscr,
            'capital' => $this->capital($settings, $position['net_equity']),
            'pfn' => $this->pfn($settings, $position, $openingCash, $ebitdaDelta),
            'liquidity' => $this->liquidity($projection, $settings),
        ];
    }

    /**
     * The ledger figures of the tenant at a date.
     *
     * @return array{net_equity: string, financial_debt: string, ebitda: string}
     */
    public function position(string $tenantId, CarbonImmutable $asOf): array
    {
        return $this->ledger->read($tenantId, $asOf);
    }

    /**
     * Operating cash flow of the flows: inflows less operating outflows, without capex, debt service and financing.
     *
     * @param  list<Flow>  $flows
     */
    public function operatingCashFlow(array $flows): string
    {
        $total = '0';

        foreach ($flows as $flow) {
            if ($this->isFinancing($flow) || $flow->isCapex() || $flow->isDebtService()) {
                continue;
            }

            $total = $flow->isInflow() ? Decimal::add($total, $flow->amount) : Decimal::sub($total, $flow->amount);
        }

        return Decimal::round2($total);
    }

    /**
     * @param  list<Flow>  $flows
     * @return array<string, mixed>
     */
    private function dscr(array $flows, CarbonImmutable $start, int $horizonDays, LiquiditySettings $settings): array
    {
        $end = $start->addDays($horizonDays);
        $inWindow = array_values(array_filter($flows, static fn (Flow $flow): bool => $flow->date->lessThanOrEqualTo($end)));
        $operating = $this->operatingCashFlow($inWindow);
        $principal = '0';
        $interest = '0';
        $grouped = [];
        $lines = [];

        foreach ($inWindow as $flow) {
            if ($this->isFinancing($flow) || $flow->isCapex()) {
                continue;
            }

            if ($flow->isDebtService()) {
                $component = $flow->category === 'debt_principal' ? 'principal' : 'interest';
                $principal = $component === 'principal' ? Decimal::add($principal, $flow->amount) : $principal;
                $interest = $component === 'interest' ? Decimal::add($interest, $flow->amount) : $interest;
                $lines[] = ['date' => $flow->date->toDateString(), 'kind' => $component, 'category' => $flow->category, 'label_key' => $flow->labelKey, 'label' => $flow->label, 'tier' => $flow->tier, 'amount' => Decimal::round2($flow->amount)];

                continue;
            }

            $kind = $flow->isInflow() ? 'operating_inflow' : 'operating_outflow';
            $key = $flow->date->format('Y-m').'|'.$kind.'|'.$flow->category.'|'.$flow->tier;
            $grouped[$key] ??= ['date' => $flow->date->startOfMonth()->toDateString(), 'kind' => $kind, 'category' => $flow->category, 'label_key' => 'lpApi.category.'.$flow->category, 'label' => null, 'tier' => $flow->tier, 'amount' => '0'];
            $grouped[$key]['amount'] = Decimal::add($grouped[$key]['amount'], $flow->amount);
        }

        ksort($grouped);

        foreach ($grouped as $line) {
            $lines[] = ['amount' => Decimal::round2($line['amount'])] + $line;
        }

        usort($lines, static fn (array $a, array $b): int => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);

        $service = Decimal::add($principal, $interest);
        $critical = (string) $settings->dscr_critical_threshold;
        $warning = (string) $settings->dscr_warning_threshold;
        $value = null;
        $status = 'not_applicable';

        if (Decimal::isPositive($service)) {
            $ratio = Decimal::div($operating, $service);
            $value = Decimal::round2($ratio);
            $status = Decimal::compare($ratio, $critical) < 0 ? 'critical' : (Decimal::compare($ratio, $warning) < 0 ? 'warning' : 'healthy');
        }

        return [
            'value' => $value,
            'status' => $status,
            'operating_cash_flow' => $operating,
            'debt_service' => ['principal' => Decimal::round2($principal), 'interest' => Decimal::round2($interest), 'total' => Decimal::round2($service)],
            'thresholds' => ['critical' => Decimal::round2($critical), 'warning' => Decimal::round2($warning)],
            'horizon_days' => $horizonDays,
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capital(LiquiditySettings $settings, string $ledgerEquity): array
    {
        $equity = $settings->net_equity_override !== null ? Decimal::round2((string) $settings->net_equity_override) : $ledgerEquity;
        $capital = $settings->share_capital;
        $minimum = $settings->legal_minimum_capital;
        $regime = $settings->jurisdiction === 'CH' ? 'CH_CO_725' : 'IT_CC_2446_2447';
        $base = [
            'regime' => $regime,
            'configured' => $capital !== null && Decimal::isPositive((string) $capital),
            'share_capital' => $capital === null ? null : Decimal::round2((string) $capital),
            'legal_minimum' => $minimum === null ? null : Decimal::round2((string) $minimum),
            'net_equity' => Decimal::round2($equity),
            'half_capital' => null,
            'coverage_percent' => null,
            'status' => 'ok',
            'source' => $settings->net_equity_override !== null ? 'override' : 'ledger',
        ];

        if (! $base['configured']) {
            return $base;
        }

        $capital = (string) $capital;
        $half = Decimal::round2(Decimal::div($capital, '2'));
        $base['half_capital'] = $half;
        $base['coverage_percent'] = Decimal::round2(Decimal::mul(Decimal::div($equity, $capital), '100'));

        if ($settings->jurisdiction === 'CH') {
            $status = Decimal::isNegative($equity) ? 'over_indebted' : (Decimal::compare($equity, $half) < 0 ? 'half_capital_lost' : (Decimal::compare($equity, $capital) < 0 ? 'watch' : 'ok'));
        } else {
            $loss = Decimal::sub($capital, $equity);
            $status = match (true) {
                $minimum !== null && Decimal::compare($equity, (string) $minimum) < 0 => 'below_legal_minimum',
                Decimal::compare($loss, Decimal::div($capital, '2')) > 0 => 'half_capital_lost',
                Decimal::compare($loss, Decimal::div($capital, '3')) > 0 => 'watch',
                default => 'ok',
            };
        }

        $base['status'] = $status;

        return $base;
    }

    /**
     * @param  array{net_equity: string, financial_debt: string, ebitda: string}  $position
     * @return array<string, mixed>
     */
    private function pfn(LiquiditySettings $settings, array $position, string $cash, string $ebitdaDelta): array
    {
        $baseEbitda = $settings->ebitda_override !== null ? (string) $settings->ebitda_override : $position['ebitda'];
        $ebitda = Decimal::round2(Decimal::add($baseEbitda, $ebitdaDelta));
        $debt = $position['financial_debt'];
        $pfn = Decimal::round2(Decimal::sub($debt, $cash));
        $max = (string) $settings->max_pfn_ebitda;
        $ratio = null;
        $status = 'not_applicable';

        if (Decimal::isPositive($ebitda)) {
            $value = Decimal::div($pfn, $ebitda);
            $ratio = Decimal::round2($value);
            $status = Decimal::compare($value, $max) > 0 ? 'breach' : (Decimal::compare($value, Decimal::mul($max, '0.8')) > 0 ? 'watch' : 'ok');
        } elseif (Decimal::isPositive($pfn)) {
            $status = 'breach';
        } elseif (Decimal::isPositive($debt) || ! Decimal::isZero($ebitda)) {
            $status = 'ok';
        }

        return ['financial_debt' => Decimal::round2($debt), 'cash' => Decimal::round2($cash), 'pfn' => $pfn, 'ebitda' => $ebitda, 'pfn_ebitda' => $ratio, 'max_ratio' => Decimal::round2($max), 'status' => $status];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function liquidity(array $projection, LiquiditySettings $settings): array
    {
        $minimum = Decimal::round2((string) $settings->minimum_liquidity);
        $negative = $projection['negative_balance'];
        $lowest = null;

        foreach ($projection['daily'] as $point) {
            if ($lowest === null || Decimal::compare((string) $point['closing'], $lowest) < 0) {
                $lowest = (string) $point['closing'];
            }
        }

        $runway = null;

        if ($negative !== null) {
            $first = CarbonImmutable::parse((string) $negative['first_date']);
            $runway = (int) CarbonImmutable::parse((string) $projection['daily'][0]['date'])->diffInDays($first);
        }

        $status = $negative !== null ? 'negative' : ($lowest !== null && Decimal::compare($lowest, $minimum) < 0 ? 'watch' : 'ok');

        return ['minimum_balance' => $minimum, 'runway_days' => $runway, 'first_negative_date' => $negative['first_date'] ?? null, 'lowest_balance' => $lowest === null ? null : Decimal::round2($lowest), 'status' => $status];
    }

    private function isFinancing(Flow $flow): bool
    {
        return $flow->labelKey === 'lpApi.category.additional_financing';
    }
}
