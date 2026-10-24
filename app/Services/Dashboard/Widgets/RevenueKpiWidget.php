<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Widgets;

use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Net invoiced amount of a period (credit notes subtract, proformas are ignored) compared with the previous period.
 */
class RevenueKpiWidget extends AbstractDashboardWidget
{
    private const PERIODS = ['current_month', 'previous_month', 'current_quarter', 'current_year', 'last_30_days'];

    public function getId(): string
    {
        return 'revenue_kpi';
    }

    public function getType(): string
    {
        return 'kpi_card';
    }

    public function getDefaultDimensions(): array
    {
        return ['w' => 4, 'h' => 3];
    }

    public function getConfigurableOptions(): array
    {
        return ['period' => ['type' => 'enum', 'values' => self::PERIODS, 'default' => 'current_month']];
    }

    public function getData(array $settings = []): array
    {
        $period = $this->resolveSettings($settings)['period'];
        [$current, $previous] = $this->ranges($period);

        $value = $this->revenue($current);
        $before = $this->revenue($previous);

        return [
            'period' => $period,
            'currency' => $this->currency(),
            'from' => $current[0]->toDateString(),
            'to' => $current[1]->toDateString(),
            'value' => $value['total'],
            'invoices_count' => $value['count'],
            'previous_from' => $previous[0]->toDateString(),
            'previous_to' => $previous[1]->toDateString(),
            'previous_value' => $before['total'],
            'change_percent' => $before['total'] != 0.0 ? round(($value['total'] - $before['total']) / abs($before['total']) * 100, 1) : null,
        ];
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return array{total: float, count: int}
     */
    private function revenue(array $range): array
    {
        $row = Invoice::query()
            ->where('invoice_type', '!=', 'proforma')
            ->whereBetween('date', [$range[0]->toDateString(), $range[1]->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN invoice_type = 'credit_note' THEN -total_net ELSE total_net END), 0) as total, COUNT(*) as count")
            ->first();

        return ['total' => round((float) $row->total, 2), 'count' => (int) $row->count];
    }

    /**
     * The selected period and the one right before it.
     *
     * @return array{0: array{0: CarbonImmutable, 1: CarbonImmutable}, 1: array{0: CarbonImmutable, 1: CarbonImmutable}}
     */
    private function ranges(string $period): array
    {
        $now = CarbonImmutable::now();

        if ($period === 'last_30_days') {
            $from = $now->subDays(29)->startOfDay();

            return [[$from, $now->endOfDay()], [$from->subDays(30), $from->subDay()->endOfDay()]];
        }

        $start = match ($period) {
            'previous_month' => $now->subMonthNoOverflow()->startOfMonth(),
            'current_quarter' => $now->startOfQuarter(),
            'current_year' => $now->startOfYear(),
            default => $now->startOfMonth(),
        };
        $months = match ($period) {
            'current_quarter' => 3,
            'current_year' => 12,
            default => 1,
        };
        $previousStart = $start->subMonthsNoOverflow($months);

        return [
            [$start, $start->addMonthsNoOverflow($months)->subDay()->endOfDay()],
            [$previousStart, $start->subDay()->endOfDay()],
        ];
    }
}
