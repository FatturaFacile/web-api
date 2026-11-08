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
use RoundingMode;

/**
 * Treasury KPIs of one currency from the invoices and the cash of the tenant:
 *
 * - DSO = open customer receivables / gross sales of the period x days of the period;
 * - DPO = open supplier payables / gross purchases of the period x days of the period;
 * - cash conversion cycle = DSO - DPO (+ DIO): the inventory days are not derived here, so the cycle is reported
 *   without them and `includes_inventory` is false;
 * - overdue receivables and payables, cash, net liquidity (cash + receivables - payables) and quick ratio.
 *
 * A ratio whose denominator is zero is null, never estimated. Credit notes reduce sales and purchases.
 */
final readonly class TreasuryKpiService
{
    /**
     * The KPIs of a currency (default: the accounting currency) at a date, over the period that ends on it.
     *
     * @return array<string, mixed>
     */
    public function kpis(string $tenantId, CarbonImmutable $asOf, int $periodDays, ?string $currency): array
    {
        $currency = strtoupper($currency ?? TenantAccountingCurrency::of($tenantId));
        $from = $asOf->subDays($periodDays - 1)->toDateString();
        $to = $asOf->toDateString();

        $sales = '0';
        foreach (Invoice::query()->where('tenant_id', $tenantId)->where('currency', $currency)->where('invoice_type', '!=', 'proforma')->whereBetween('date', [$from, $to])->get(['invoice_type', 'total_gross']) as $invoice) {
            $sales = Decimal::add($sales, $invoice->invoice_type === 'credit_note' ? Decimal::neg((string) $invoice->total_gross) : (string) $invoice->total_gross);
        }

        $purchases = '0';
        foreach (PassiveInvoice::query()->where('tenant_id', $tenantId)->where('currency', $currency)->whereBetween('date', [$from, $to])->get(['document_type', 'total_amount']) as $invoice) {
            $purchases = Decimal::add($purchases, $invoice->document_type === 'TD04' ? Decimal::neg((string) $invoice->total_amount) : (string) $invoice->total_amount);
        }

        [$receivables, $overdueReceivables] = $this->openReceivables($tenantId, $currency, $asOf);
        [$payables, $overduePayables] = $this->openPayables($tenantId, $currency, $asOf);
        $cash = Decimal::add(
            (string) (BankAccount::query()->where('is_active', true)->where('currency', $currency)->sum('current_balance') ?: '0'),
            (string) (CashRegister::query()->where('is_active', true)->where('currency', $currency)->sum('current_balance') ?: '0'),
        );

        $dso = $this->days($receivables, $sales, $periodDays);
        $dpo = $this->days($payables, $purchases, $periodDays);

        return [
            'as_of' => $to,
            'period_from' => $from,
            'period_days' => $periodDays,
            'currency' => $currency,
            'sales' => Decimal::round2($sales),
            'purchases' => Decimal::round2($purchases),
            'open_receivables' => Decimal::round2($receivables),
            'overdue_receivables' => Decimal::round2($overdueReceivables),
            'open_payables' => Decimal::round2($payables),
            'overdue_payables' => Decimal::round2($overduePayables),
            'cash' => Decimal::round2($cash),
            'net_liquidity' => Decimal::round2(Decimal::sub(Decimal::add($cash, $receivables), $payables)),
            'quick_ratio' => Decimal::isPositive($payables) ? bcadd(Decimal::div(Decimal::add($cash, $receivables), $payables), '0', 3) : null,
            'dso_days' => $dso,
            'dpo_days' => $dpo,
            'dio_days' => null,
            'cash_conversion_cycle_days' => $dso === null || $dpo === null ? null : bcsub($dso, $dpo, 1),
            'includes_inventory' => false,
        ];
    }

    /**
     * @return array{0: string, 1: string} open and overdue receivables
     */
    private function openReceivables(string $tenantId, string $currency, CarbonImmutable $asOf): array
    {
        $open = '0';
        $overdue = '0';

        foreach (Invoice::query()->where('tenant_id', $tenantId)->where('currency', $currency)->whereNotIn('invoice_type', ['proforma', 'credit_note'])->where('payment_status', '!=', 'paid')->whereDate('date', '<=', $asOf->toDateString())->get() as $invoice) {
            $amount = $invoice->outstandingAmount();

            if (! Decimal::isPositive($amount)) {
                continue;
            }

            $open = Decimal::add($open, $amount);
            $overdue = $invoice->due_date && CarbonImmutable::parse($invoice->due_date)->lessThan($asOf) ? Decimal::add($overdue, $amount) : $overdue;
        }

        return [$open, $overdue];
    }

    /**
     * @return array{0: string, 1: string} open and overdue payables
     */
    private function openPayables(string $tenantId, string $currency, CarbonImmutable $asOf): array
    {
        $open = '0';
        $overdue = '0';

        foreach (PassiveInvoice::query()->where('tenant_id', $tenantId)->where('currency', $currency)->where('document_type', '!=', 'TD04')->where('payment_status', '!=', 'paid')->whereDate('date', '<=', $asOf->toDateString())->get() as $invoice) {
            $amount = Decimal::sub($invoice->amountDue(), (string) ($invoice->total_paid ?? '0'));

            if (! Decimal::isPositive($amount)) {
                continue;
            }

            $open = Decimal::add($open, $amount);
            $overdue = $invoice->due_date && CarbonImmutable::parse($invoice->due_date)->lessThan($asOf) ? Decimal::add($overdue, $amount) : $overdue;
        }

        return [$open, $overdue];
    }

    private function days(string $balance, string $flow, int $periodDays): ?string
    {
        if (! Decimal::isPositive($flow)) {
            return null;
        }

        return bcround(Decimal::mul(Decimal::div($balance, $flow), (string) $periodDays), 1, RoundingMode::HalfAwayFromZero);
    }
}
