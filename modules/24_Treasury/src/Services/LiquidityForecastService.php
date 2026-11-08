<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashRegister;
use Modules\Treasury\Models\ForecastScenario;
use Modules\Treasury\Models\RecurringCashItem;

/**
 * Cash position and liquidity forecast of one currency, computed on request from the live records:
 *
 * - opening cash: balances of the active treasury bank accounts and cash registers in the currency;
 * - inflows: open balances of the issued customer invoices (credit notes and proformas excluded), on their due date
 *   (overdue ones on the first day), plus the recurring inflows;
 * - outflows: open balances of the supplier invoices (credit notes excluded), on their due date, plus the recurring
 *   outflows.
 *
 * A scenario (`forecast_scenarios`) shifts collections later by `collection_delay_days`, pays suppliers earlier by
 * `disbursement_acceleration_days` and scales the expected inflows by `sales_growth_factor`; the implicit baseline
 * changes nothing. Amounts are decimal strings; nothing is stored.
 */
final readonly class LiquidityForecastService
{
    /**
     * The forecast of each requested scenario.
     *
     * @param  list<string>  $scenarioCodes  empty for the baseline only
     * @return array<string, mixed>
     */
    public function forecast(string $tenantId, int $horizonDays, array $scenarioCodes, ?string $currency, ?string $minimumBalance, ?CarbonImmutable $asOf = null): array
    {
        $start = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $end = $start->addDays($horizonDays);
        $currency = strtoupper($currency ?? TenantAccountingCurrency::of($tenantId));
        $opening = $this->openingCash($currency);
        $flows = $this->flows($tenantId, $currency, $start, $end);
        $scenarios = $this->scenarios($scenarioCodes);

        return [
            'as_of' => $start->toDateString(),
            'until' => $end->toDateString(),
            'currency' => $currency,
            'opening_cash' => $opening,
            'minimum_balance' => $minimumBalance === null ? null : Decimal::round2($minimumBalance),
            'scenarios' => array_map(fn (array $scenario): array => $this->project($scenario, $flows, $opening, $start, $end, $minimumBalance), $scenarios),
        ];
    }

    /**
     * The cash position: balance of every active bank account and cash register, and the total per currency.
     *
     * @return array<string, mixed>
     */
    public function position(): array
    {
        $accounts = BankAccount::query()->where('is_active', true)->orderBy('currency')->orderBy('bank_name')->get();
        $registers = CashRegister::query()->where('is_active', true)->orderBy('currency')->orderBy('code')->get();
        $totals = [];

        foreach ($accounts as $account) {
            $totals[$account->currency]['bank'] = Decimal::round2(Decimal::add($totals[$account->currency]['bank'] ?? '0', (string) $account->current_balance));
        }

        foreach ($registers as $register) {
            $totals[$register->currency]['cash'] = Decimal::round2(Decimal::add($totals[$register->currency]['cash'] ?? '0', (string) $register->current_balance));
        }

        return [
            'as_of' => now()->toIso8601String(),
            'bank_accounts' => $accounts->map(static fn (BankAccount $account): array => [
                'id' => $account->id,
                'bank_name' => $account->bank_name,
                'iban' => $account->iban,
                'currency' => $account->currency,
                'current_balance' => Decimal::round2((string) $account->current_balance),
                'available_balance' => Decimal::round2((string) $account->available_balance),
                'overdraft_limit' => Decimal::round2((string) ($account->overdraft_limit ?? '0')),
            ])->values()->all(),
            'cash_registers' => $registers->map(static fn (CashRegister $register): array => [
                'id' => $register->id,
                'code' => $register->code,
                'currency' => $register->currency,
                'current_balance' => Decimal::round2((string) $register->current_balance),
            ])->values()->all(),
            'totals' => collect($totals)->map(static fn (array $sums, string $currency): array => [
                'currency' => $currency,
                'bank' => $sums['bank'] ?? '0.00',
                'cash' => $sums['cash'] ?? '0.00',
                'total' => Decimal::round2(Decimal::add($sums['bank'] ?? '0', $sums['cash'] ?? '0')),
            ])->values()->all(),
        ];
    }

    private function openingCash(string $currency): string
    {
        $bank = (string) BankAccount::query()->where('is_active', true)->where('currency', $currency)->sum('current_balance');
        $cash = (string) CashRegister::query()->where('is_active', true)->where('currency', $currency)->sum('current_balance');

        return Decimal::round2(Decimal::add($bank ?: '0', $cash ?: '0'));
    }

    /**
     * The expected flows of the horizon before any scenario.
     *
     * @return list<array{date: CarbonImmutable, direction: string, source: string, reference: string, amount: string}>
     */
    private function flows(string $tenantId, string $currency, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $flows = [];

        $receivables = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->where('payment_status', '!=', 'paid')
            ->where(fn ($query) => $query->whereNull('due_date')->orWhereDate('due_date', '<=', $end->toDateString()))
            ->get();

        foreach ($receivables as $invoice) {
            $open = $invoice->outstandingAmount();

            if (Decimal::isPositive($open)) {
                $flows[] = ['date' => $this->onOrAfter($invoice->due_date ?? $invoice->date, $start), 'direction' => 'inflow', 'source' => 'receivable', 'reference' => (string) $invoice->invoice_number, 'amount' => $open];
            }
        }

        $payables = PassiveInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->where('document_type', '!=', 'TD04')
            ->where('payment_status', '!=', 'paid')
            ->where(fn ($query) => $query->whereNull('due_date')->orWhereDate('due_date', '<=', $end->toDateString()))
            ->get();

        foreach ($payables as $invoice) {
            $open = Decimal::round2(Decimal::sub($invoice->amountDue(), (string) ($invoice->total_paid ?? '0')));

            if (Decimal::isPositive($open)) {
                $flows[] = ['date' => $this->onOrAfter($invoice->due_date ?? $invoice->date, $start), 'direction' => 'outflow', 'source' => 'payable', 'reference' => (string) $invoice->invoice_number, 'amount' => $open];
            }
        }

        foreach (RecurringCashItem::query()->where('is_active', true)->where('currency', $currency)->get() as $item) {
            foreach ($item->occurrencesBetween($start, $end) as $date) {
                $flows[] = ['date' => $date, 'direction' => $item->direction, 'source' => 'recurring', 'reference' => (string) $item->name, 'amount' => Decimal::round2((string) $item->amount)];
            }
        }

        return $flows;
    }

    /**
     * The scenarios to project: the requested ones, or the implicit baseline.
     *
     * @param  list<string>  $codes
     * @return list<array{code: string, name: string, collection_delay_days: int, disbursement_acceleration_days: int, sales_growth_factor: string}>
     */
    private function scenarios(array $codes): array
    {
        $baseline = ['code' => 'BASELINE', 'name' => 'Baseline', 'collection_delay_days' => 0, 'disbursement_acceleration_days' => 0, 'sales_growth_factor' => '1'];

        if ($codes === []) {
            return [$baseline];
        }

        $stored = ForecastScenario::query()->whereIn('code', $codes)->get()->keyBy('code');

        return array_values(array_map(static fn (string $code): array => $stored->has($code) ? [
            'code' => $code,
            'name' => (string) $stored[$code]->name,
            'collection_delay_days' => (int) $stored[$code]->collection_delay_days,
            'disbursement_acceleration_days' => (int) $stored[$code]->disbursement_acceleration_days,
            'sales_growth_factor' => (string) $stored[$code]->sales_growth_factor,
        ] : ['code' => $code] + $baseline, array_unique($codes)));
    }

    /**
     * Applies a scenario and returns the daily projection.
     *
     * @param  array{code: string, name: string, collection_delay_days: int, disbursement_acceleration_days: int, sales_growth_factor: string}  $scenario
     * @param  list<array{date: CarbonImmutable, direction: string, source: string, reference: string, amount: string}>  $flows
     * @return array<string, mixed>
     */
    private function project(array $scenario, array $flows, string $opening, CarbonImmutable $start, CarbonImmutable $end, ?string $minimumBalance): array
    {
        $days = [];
        $totals = ['inflow' => '0', 'outflow' => '0'];
        $bySource = [];

        foreach ($flows as $flow) {
            $isInflow = $flow['direction'] === 'inflow';
            $date = $isInflow
                ? $flow['date']->addDays($scenario['collection_delay_days'])
                : $flow['date']->subDays($scenario['disbursement_acceleration_days'])->max($start);

            if ($date->greaterThan($end)) {
                continue;
            }

            $amount = $isInflow ? Decimal::round2(Decimal::mul($flow['amount'], $scenario['sales_growth_factor'])) : $flow['amount'];
            $key = $date->toDateString();
            $days[$key][$flow['direction']] = Decimal::add($days[$key][$flow['direction']] ?? '0', $amount);
            $totals[$flow['direction']] = Decimal::add($totals[$flow['direction']], $amount);
            $bySource[$flow['source']] = Decimal::add($bySource[$flow['source']] ?? '0', $isInflow ? $amount : Decimal::neg($amount));
        }

        ksort($days);
        $running = $opening;
        $lowest = ['date' => $start->toDateString(), 'balance' => Decimal::round2($opening)];
        $firstBelow = $minimumBalance !== null && Decimal::compare($opening, $minimumBalance) < 0 ? $start->toDateString() : null;
        $series = [];

        foreach ($days as $date => $sums) {
            $running = Decimal::add($running, Decimal::sub($sums['inflow'] ?? '0', $sums['outflow'] ?? '0'));
            $series[] = ['date' => $date, 'inflows' => Decimal::round2($sums['inflow'] ?? '0'), 'outflows' => Decimal::round2($sums['outflow'] ?? '0'), 'balance' => Decimal::round2($running)];

            if (Decimal::compare($running, $lowest['balance']) < 0) {
                $lowest = ['date' => $date, 'balance' => Decimal::round2($running)];
            }

            if ($firstBelow === null && $minimumBalance !== null && Decimal::compare($running, $minimumBalance) < 0) {
                $firstBelow = $date;
            }
        }

        return [
            'scenario' => $scenario['code'],
            'name' => $scenario['name'],
            'assumptions' => [
                'collection_delay_days' => $scenario['collection_delay_days'],
                'disbursement_acceleration_days' => $scenario['disbursement_acceleration_days'],
                'sales_growth_factor' => $scenario['sales_growth_factor'],
            ],
            'total_inflows' => Decimal::round2($totals['inflow']),
            'total_outflows' => Decimal::round2($totals['outflow']),
            'closing_balance' => Decimal::round2($running),
            'lowest_balance' => $lowest,
            'first_day_below_minimum' => $firstBelow,
            'net_by_source' => array_map(static fn (string $sum): string => Decimal::round2($sum), $bySource),
            'days' => $series,
        ];
    }

    private function onOrAfter(mixed $date, CarbonImmutable $start): CarbonImmutable
    {
        return $date === null ? $start : CarbonImmutable::parse($date)->startOfDay()->max($start);
    }
}
