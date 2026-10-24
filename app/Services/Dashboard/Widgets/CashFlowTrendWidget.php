<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Widgets;

use App\Models\PassiveInvoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;

/**
 * Money in (customer payments by payment date) against money out (paid supplier invoices by due date, credit notes
 * excluded) for the latest months, current month included.
 */
class CashFlowTrendWidget extends AbstractDashboardWidget
{
    public function getId(): string
    {
        return 'cash_flow_trend';
    }

    public function getType(): string
    {
        return 'line_chart';
    }

    public function getDefaultDimensions(): array
    {
        return ['w' => 8, 'h' => 4];
    }

    public function getConfigurableOptions(): array
    {
        return ['months' => ['type' => 'integer', 'min' => 2, 'max' => 12, 'default' => 6]];
    }

    public function getData(array $settings = []): array
    {
        $months = $this->resolveSettings($settings)['months'];
        $first = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $last = $first->addMonthsNoOverflow($months - 1)->endOfMonth();

        $inflow = Payment::query()
            ->where('type', 'payment')
            ->whereBetween('paid_at', [$first->toDateString(), $last->toDateString()])
            ->selectRaw("{$this->monthBucket('paid_at')} as bucket, SUM(amount) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $outflow = PassiveInvoice::query()
            ->where('payment_status', 'paid')
            ->where('document_type', '!=', 'TD04')
            ->whereRaw('COALESCE(due_date, date) BETWEEN ? AND ?', [$first->toDateString(), $last->toDateString()])
            ->selectRaw("{$this->monthBucket('COALESCE(due_date, date)')} as bucket, SUM(total_amount) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $series = [];

        for ($month = $first; $month <= $last; $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $in = round((float) ($inflow[$key] ?? 0), 2);
            $out = round((float) ($outflow[$key] ?? 0), 2);
            $series[] = ['month' => $key, 'inflow' => $in, 'outflow' => $out, 'net' => round($in - $out, 2)];
        }

        return [
            'currency' => $this->currency(),
            'months' => $series,
            'total_inflow' => round(array_sum(array_column($series, 'inflow')), 2),
            'total_outflow' => round(array_sum(array_column($series, 'outflow')), 2),
        ];
    }
}
