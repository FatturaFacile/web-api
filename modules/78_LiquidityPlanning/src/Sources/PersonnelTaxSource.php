<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Models\F24Payment;
use Modules\LiquidityPlanning\Support\Flow;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\SwissVat\Models\SwissVatReturn;
use Modules\Treasury\Models\F24Delegation;

/**
 * The estimated components: the fixed personnel cost (salaries and employer social charges, from the last final payroll run or
 * the setting) paid every month, and the periodic taxes (F24 payments, VAT settlements, Swiss VAT returns, F24 delegations
 * of the treasury and the monthly estimate of the setting).
 */
final class PersonnelTaxSource implements FlowSource
{
    public function flows(SourceContext $context): array
    {
        return [...$this->payroll($context), ...$this->taxes($context)];
    }

    /**
     * @return list<Flow>
     */
    private function payroll(SourceContext $context): array
    {
        $amount = $context->settings->payroll_monthly_override;

        if ($amount === null || ! Decimal::isPositive((string) $amount)) {
            $run = PayrollRun::query()->where('status', PayrollRun::StatusFinal)->where('currency', $context->currency)->orderByDesc('year')->orderByDesc('month')->first();
            $amount = '0';

            if ($run !== null) {
                $payslips = Payslip::query()->where('payroll_run_id', $run->id)->get(['gross_salary', 'employer_contributions']);

                foreach ($payslips as $payslip) {
                    $amount = Decimal::add($amount, Decimal::add((string) $payslip->gross_salary, (string) $payslip->employer_contributions));
                }
            }
        }

        if (! Decimal::isPositive((string) $amount)) {
            return [];
        }

        $flows = [];
        $day = max(1, min(28, $context->settings->payroll_pay_day));

        for ($month = $context->start->startOfMonth(); $month->lessThanOrEqualTo($context->end); $month = $month->addMonthNoOverflow()) {
            $date = $month->day($day);

            if ($date->betweenIncluded($context->start, $context->end)) {
                $flows[] = new Flow($date, Flow::Outflow, Flow::Estimated, 'payroll', 'Payroll', Decimal::round2((string) $amount), 'lpApi.category.payroll');
            }
        }

        return $flows;
    }

    /**
     * @return list<Flow>
     */
    private function taxes(SourceContext $context): array
    {
        $flows = [];
        $hasF24 = false;

        if (Schema::hasTable('it_f24_payments')) {
            foreach (F24Payment::query()->whereNull('paid_at')->whereDate('due_date', '<=', $context->end->toDateString())->get() as $payment) {
                $open = Decimal::round2(Decimal::sub((string) $payment->amount_due, (string) $payment->amount_paid));
                $hasF24 = true;

                if (Decimal::isPositive($open)) {
                    $flows[] = new Flow($context->onOrAfterStart($payment->due_date), Flow::Outflow, Flow::Estimated, 'tax', 'F24 '.$payment->tax_code, $open, 'lpApi.category.tax');
                }
            }
        }

        if (! $hasF24 && Schema::hasTable('vat_settlements')) {
            foreach (VatSettlement::query()->where('is_paid', false)->where('payable_amount', '>', 0)->get() as $settlement) {
                $due = CarbonImmutable::parse($settlement->period_to)->addMonthNoOverflow()->day(16);

                if ($due->lessThanOrEqualTo($context->end)) {
                    $flows[] = new Flow($context->onOrAfterStart($due), Flow::Outflow, Flow::Estimated, 'tax', 'VAT', Decimal::round2((string) $settlement->payable_amount), 'lpApi.category.tax');
                }
            }
        }

        if (Schema::hasTable('swiss_vat_returns')) {
            foreach (SwissVatReturn::query()->whereNull('submitted_at')->where('box_500_payable_amount', '>', 0)->get() as $return) {
                $due = CarbonImmutable::parse($return->period_to)->addDays(60);

                if ($due->lessThanOrEqualTo($context->end)) {
                    $flows[] = new Flow($context->onOrAfterStart($due), Flow::Outflow, Flow::Estimated, 'tax', 'MWST', Decimal::round2((string) $return->box_500_payable_amount), 'lpApi.category.tax');
                }
            }
        }

        foreach (F24Delegation::query()->whereIn('status', ['draft', 'validated', 'generated'])->whereDate('payment_date', '<=', $context->end->toDateString())->get() as $delegation) {
            if (Decimal::isPositive((string) $delegation->total_payable)) {
                $flows[] = new Flow($context->onOrAfterStart($delegation->payment_date), Flow::Outflow, Flow::Estimated, 'tax', 'F24', Decimal::round2((string) $delegation->total_payable), 'lpApi.category.tax');
            }
        }

        $estimate = $context->settings->tax_monthly_estimate;

        if ($estimate !== null && Decimal::isPositive((string) $estimate)) {
            for ($month = $context->start->startOfMonth(); $month->lessThanOrEqualTo($context->end); $month = $month->addMonthNoOverflow()) {
                $date = $month->day(16);

                if ($date->betweenIncluded($context->start, $context->end)) {
                    $flows[] = new Flow($date, Flow::Outflow, Flow::Estimated, 'tax', 'Tax estimate', Decimal::round2((string) $estimate), 'lpApi.category.tax');
                }
            }
        }

        return $flows;
    }
}
