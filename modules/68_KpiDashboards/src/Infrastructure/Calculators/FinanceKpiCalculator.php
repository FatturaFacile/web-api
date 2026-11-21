<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Calculators;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Services\IntercompanyEliminator;
use Modules\KpiDashboards\Infrastructure\Services\KpiContext;
use Modules\KpiDashboards\Infrastructure\Services\KpiFx;
use Modules\KpiDashboards\Infrastructure\Services\LedgerAggregator;
use stdClass;

/**
 * CFO cockpit: DSO, DPO, net working capital, cash runway, AR/AP aging and VAT liability. Receivables and payables come from
 * the sales and purchase invoices (open amount = gross minus paid, in the accounting currency at the frozen document rate;
 * credit notes count negative, proformas never). Invoices carry no legal entity, so the entity filter narrows the ledger
 * figures only; the intercompany elimination subtracts the intercompany receivables and payables in consolidated view.
 */
final class FinanceKpiCalculator implements KpiCalculator
{
    private const array Buckets = ['current', '1_30', '31_60', '61_90', 'over_90'];

    public function __construct(
        private readonly LedgerAggregator $ledger,
        private readonly IntercompanyEliminator $eliminator,
        private readonly KpiFx $fx,
    ) {}

    public function supports(): array
    {
        return [KpiCode::Dso, KpiCode::Dpo, KpiCode::NetWorkingCapital, KpiCode::CashRunway, KpiCode::ArApAging, KpiCode::VatLiability];
    }

    public function headline(KpiCode $kpi, CockpitFilter $filter): ?float
    {
        return match ($kpi) {
            KpiCode::Dso => $this->dso($filter),
            KpiCode::Dpo => $this->dpo($filter),
            KpiCode::NetWorkingCapital => $this->nwc($filter)['nwc'],
            KpiCode::CashRunway => $this->runway($filter)['months'],
            KpiCode::ArApAging => $this->openReceivables($filter) - $this->openPayables($filter),
            KpiCode::VatLiability => array_sum(array_column($this->vat($filter), 'liability')),
            default => null,
        };
    }

    public function payload(KpiCode $kpi, KpiContext $context): array
    {
        $filter = $context->filter;

        return match ($kpi) {
            KpiCode::Dso => ['unit' => 'days', 'target' => 45, 'trend' => $this->dsoTrend($filter)],
            KpiCode::Dpo => ['unit' => 'days', 'target' => 45, 'trend' => $this->dpoTrend($filter)],
            KpiCode::NetWorkingCapital => $this->nwcPayload($context),
            KpiCode::CashRunway => $this->runwayPayload($context),
            KpiCode::ArApAging => [
                'unit' => 'currency',
                'buckets' => self::Buckets,
                'receivables' => $this->moneyBuckets($context, $this->aging($filter, 'receivable')),
                'payables' => $this->moneyBuckets($context, $this->aging($filter, 'payable')),
            ],
            KpiCode::VatLiability => [
                'unit' => 'currency',
                'jurisdictions' => array_map(static fn (array $row): array => [
                    'jurisdiction' => $row['jurisdiction'],
                    'authority' => $row['jurisdiction'] === 'CH' ? 'ESTV/AFC MWST' : 'Agenzia delle Entrate LIPE',
                    'output_vat' => $context->money($row['output']),
                    'input_vat' => $context->money($row['input']),
                    'liability' => $context->money($row['liability']),
                ], $this->vat($filter)),
            ],
            default => [],
        };
    }

    /**
     * Sales invoices of the tenant up to the end of the period, with the open amount in the accounting currency.
     *
     * @return Collection<int, covariant object>
     */
    private function receivableRows(CockpitFilter $filter, ?CarbonImmutable $from = null): Collection
    {
        return DB::table('invoices')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->where('invoice_type', '!=', 'proforma')
            ->whereDate('date', '<=', $filter->to->toDateString())
            ->when($from !== null, static fn ($query) => $query->whereDate('date', '>=', $from?->toDateString()))
            ->get(['date', 'due_date', 'invoice_type', 'total_net', 'total_gross', 'total_paid', 'payment_status', 'exchange_rate'])
            ->map(static function (object $row): stdClass {
                $sign = $row->invoice_type === 'credit_note' ? -1.0 : 1.0;
                $rate = (float) $row->exchange_rate > 0 ? (float) $row->exchange_rate : 1.0;

                return (object) [
                    'date' => (string) $row->date,
                    'due_date' => (string) ($row->due_date ?? $row->date),
                    'net' => $sign * (float) $row->total_net * $rate,
                    'open' => $row->payment_status === 'paid' ? 0.0 : $sign * max(0.0, (float) $row->total_gross - (float) $row->total_paid) * $rate,
                ];
            });
    }

    /**
     * @return Collection<int, covariant object>
     */
    private function payableRows(CockpitFilter $filter, ?CarbonImmutable $from = null): Collection
    {
        return DB::table('passive_invoices')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereDate('date', '<=', $filter->to->toDateString())
            ->when($from !== null, static fn ($query) => $query->whereDate('date', '>=', $from?->toDateString()))
            ->get(['date', 'due_date', 'document_type', 'total_net', 'total_amount', 'total_paid', 'payment_status', 'exchange_rate'])
            ->map(static function (object $row): stdClass {
                $sign = in_array($row->document_type, ['TD04', 'TD08'], true) ? -1.0 : 1.0;
                $rate = $row->exchange_rate !== null && (float) $row->exchange_rate > 0 ? (float) $row->exchange_rate : 1.0;

                return (object) [
                    'date' => (string) $row->date,
                    'due_date' => (string) ($row->due_date ?? $row->date),
                    'net' => $sign * (float) $row->total_net * $rate,
                    'open' => $row->payment_status === 'paid' ? 0.0 : $sign * max(0.0, (float) $row->total_amount - (float) $row->total_paid) * $rate,
                ];
            });
    }

    private function openReceivables(CockpitFilter $filter): float
    {
        return round($this->receivableRows($filter)->sum('open') - $this->eliminator->eliminations($filter)['receivable'], 2);
    }

    private function openPayables(CockpitFilter $filter): float
    {
        return round($this->payableRows($filter)->sum('open') - $this->eliminator->eliminations($filter)['payable'], 2);
    }

    private function dso(CockpitFilter $filter): ?float
    {
        $sales = $this->receivableRows($filter, $filter->from)->sum('net') - $this->eliminator->eliminations($filter)['revenue'];

        return $sales > 0 ? round($this->openReceivables($filter) / $sales * $filter->days(), 2) : null;
    }

    private function dpo(CockpitFilter $filter): ?float
    {
        $purchases = $this->payableRows($filter, $filter->from)->sum('net') - $this->eliminator->eliminations($filter)['cogs'];

        return $purchases > 0 ? round($this->openPayables($filter) / $purchases * $filter->days(), 2) : null;
    }

    /**
     * Month-end DSO over the six months to the end of the period, for the trend of the gauge.
     *
     * @return list<array{period: string, value: float|null}>
     */
    private function dsoTrend(CockpitFilter $filter): array
    {
        return $this->trend($filter, fn (CockpitFilter $month): ?float => $this->dso($month));
    }

    /**
     * @return list<array{period: string, value: float|null}>
     */
    private function dpoTrend(CockpitFilter $filter): array
    {
        return $this->trend($filter, fn (CockpitFilter $month): ?float => $this->dpo($month));
    }

    /**
     * @param  callable(CockpitFilter): ?float  $metric
     * @return list<array{period: string, value: float|null}>
     */
    private function trend(CockpitFilter $filter, callable $metric): array
    {
        $points = [];

        for ($i = 5; $i >= 0; $i--) {
            $end = $filter->to->subMonthsNoOverflow($i)->endOfMonth();
            $end = $end > $filter->to ? $filter->to : $end;
            $points[] = ['period' => $end->format('Y-m'), 'value' => $metric($filter->forPeriod($end->startOfMonth(), $end))];
        }

        return $points;
    }

    /**
     * @return array{ar: float, inventory: float, ap: float, nwc: float}
     */
    private function nwc(CockpitFilter $filter): array
    {
        $ar = $this->openReceivables($filter);
        $inventory = $this->ledger->net($filter, ['asset'], null, $filter->to, 'inventory');
        $ap = $this->openPayables($filter);

        return ['ar' => $ar, 'inventory' => $inventory, 'ap' => $ap, 'nwc' => round($ar + $inventory - $ap, 2)];
    }

    /**
     * @return array<string, mixed>
     */
    private function nwcPayload(KpiContext $context): array
    {
        $parts = $this->nwc($context->filter);

        return ['unit' => 'currency', 'waterfall' => [
            ['step' => 'receivables', 'amount' => $context->money($parts['ar'])],
            ['step' => 'inventory', 'amount' => $context->money($parts['inventory'])],
            ['step' => 'payables', 'amount' => $context->money(-$parts['ap'])],
            ['step' => 'net_working_capital', 'amount' => $context->money($parts['nwc']), 'is_total' => true],
        ]];
    }

    /**
     * Cash at the end of the period (the latest treasury balance of each bank account, else the ledger cash accounts) over
     * the average monthly net burn of the last three months; null months means the company is not burning cash.
     *
     * @return array{cash: float, burn: float, months: float|null}
     */
    private function runway(CockpitFilter $filter): array
    {
        $balances = DB::table('bank_account_balances')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereDate('balance_date', '<=', $filter->to->toDateString())
            ->orderBy('balance_date')->get()->groupBy('bank_account_id')->map->last();

        $cash = $balances->isNotEmpty()
            ? $balances->sum(fn (object $balance): float => $this->fx->toAccounting($filter->tenantId, (string) $balance->currency, (float) $balance->ledger_balance, CarbonImmutable::parse($balance->balance_date)))
            : $this->ledger->net($filter, ['asset'], null, $filter->to, 'cash');

        $windowFrom = $filter->to->subMonthsNoOverflow(3)->addDay()->startOfDay();
        $revenue = $this->ledger->net($filter, ['revenue'], $windowFrom, $filter->to, null, true);
        $costs = $this->ledger->net($filter, ['cogs', 'expense'], $windowFrom, $filter->to);
        $burn = round(($costs - $revenue) / 3, 2);

        return ['cash' => round((float) $cash, 2), 'burn' => $burn, 'months' => $burn > 0 ? round($cash / $burn, 2) : null];
    }

    /**
     * @return array<string, mixed>
     */
    private function runwayPayload(KpiContext $context): array
    {
        $runway = $this->runway($context->filter);
        $series = [];

        foreach ($this->ledger->monthlyIncome($context->filter, $context->filter->to->subMonthsNoOverflow(5)->startOfMonth(), $context->filter->to) as $month => $amounts) {
            $series[] = ['period' => $month, 'net_burn' => $context->money($amounts['cogs'] + $amounts['expense'] - $amounts['revenue'])];
        }

        return [
            'unit' => 'months',
            'cash' => $context->money($runway['cash']),
            'monthly_burn' => $context->money($runway['burn']),
            'self_sustaining' => $runway['months'] === null,
            'series' => $series,
        ];
    }

    /**
     * Open amounts by days past due at the end of the period.
     *
     * @return array<string, float>
     */
    private function aging(CockpitFilter $filter, string $side): array
    {
        $rows = $side === 'receivable' ? $this->receivableRows($filter) : $this->payableRows($filter);
        $buckets = array_fill_keys(self::Buckets, 0.0);

        foreach ($rows as $row) {
            if ($row->open == 0.0) {
                continue;
            }

            $overdue = (int) CarbonImmutable::parse($row->due_date)->startOfDay()->diffInDays($filter->to->startOfDay(), false);
            $bucket = match (true) {
                $overdue <= 0 => 'current',
                $overdue <= 30 => '1_30',
                $overdue <= 60 => '31_60',
                $overdue <= 90 => '61_90',
                default => 'over_90',
            };
            $buckets[$bucket] += $row->open;
        }

        return $buckets;
    }

    /**
     * @param  array<string, float>  $buckets
     * @return array<string, float>
     */
    private function moneyBuckets(KpiContext $context, array $buckets): array
    {
        return array_map($context->money(...), $buckets);
    }

    /**
     * Output VAT of the sales invoices against input VAT of the purchase invoices dated in the period, by jurisdiction:
     * the invoice jurisdiction for sales, the country of the supplier (CH, otherwise IT) for purchases.
     *
     * @return list<array{jurisdiction: string, output: float, input: float, liability: float}>
     */
    private function vat(CockpitFilter $filter): array
    {
        $totals = ['CH' => ['output' => 0.0, 'input' => 0.0], 'IT' => ['output' => 0.0, 'input' => 0.0]];

        $sales = DB::table('invoices')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')->where('invoice_type', '!=', 'proforma')
            ->whereDate('date', '>=', $filter->from->toDateString())->whereDate('date', '<=', $filter->to->toDateString())
            ->get(['jurisdiction', 'invoice_type', 'total_tax', 'exchange_rate']);

        foreach ($sales as $invoice) {
            $key = $invoice->jurisdiction === 'CH' ? 'CH' : 'IT';
            $sign = $invoice->invoice_type === 'credit_note' ? -1.0 : 1.0;
            $totals[$key]['output'] += $sign * (float) $invoice->total_tax * ((float) $invoice->exchange_rate > 0 ? (float) $invoice->exchange_rate : 1.0);
        }

        $purchases = DB::table('passive_invoices')
            ->leftJoin('contacts', 'passive_invoices.supplier_id', '=', 'contacts.id')
            ->where('passive_invoices.tenant_id', $filter->tenantId)->whereNull('passive_invoices.deleted_at')
            ->whereDate('passive_invoices.date', '>=', $filter->from->toDateString())->whereDate('passive_invoices.date', '<=', $filter->to->toDateString())
            ->get(['contacts.country', 'passive_invoices.total_tax', 'passive_invoices.exchange_rate']);

        foreach ($purchases as $invoice) {
            $key = $invoice->country === 'CH' ? 'CH' : 'IT';
            $totals[$key]['input'] += (float) $invoice->total_tax * ($invoice->exchange_rate !== null && (float) $invoice->exchange_rate > 0 ? (float) $invoice->exchange_rate : 1.0);
        }

        $rows = [];

        foreach ($totals as $jurisdiction => $amounts) {
            $rows[] = ['jurisdiction' => $jurisdiction, 'output' => round($amounts['output'], 2), 'input' => round($amounts['input'], 2), 'liability' => round($amounts['output'] - $amounts['input'], 2)];
        }

        return $rows;
    }
}
