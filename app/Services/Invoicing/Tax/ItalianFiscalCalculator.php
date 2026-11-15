<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Exceptions\FiscalRuleException;
use App\Support\Money;
use OverflowException;

/**
 * Pure, integer-cent calculation of every fiscal figure of an Italian invoice.
 *
 * Pipeline: line net -> cassa (on the fund-eligible lines) -> VAT per (rate, natura) aggregate, base including the
 * cassa and tax rounded once per aggregate -> ritenuta -> ENASARCO -> bollo -> split payment -> amount due.
 * Rates, thresholds and wording come from config('italian_tax').
 */
class ItalianFiscalCalculator
{
    /**
     * @throws FiscalRuleException when the requested options cannot be combined
     */
    public function calculate(FiscalInput $input): FiscalResult
    {
        try {
            return $this->compute($input);
        } catch (OverflowException) {
            // Amounts beyond the integer-cent range must be a 422, never a 500.
            throw new FiscalRuleException('amount_out_of_range', 'items');
        }
    }

    private function compute(FiscalInput $input): FiscalResult
    {
        $forfettario = $input->regimeFiscale === (string) config('italian_tax.forfettario.regime', 'RF19');

        $this->validate($input, $forfettario);

        $warnings = [];
        $lines = [];

        foreach ($input->lines as $index => $line) {
            $natura = $this->lineNatura($line, $input, $forfettario);
            $rate = $natura !== null ? 0 : Money::toScaled($line->taxRate, 2);

            $amount = Money::multiplyToCents(Money::toScaled($line->quantity, 4), 4, Money::toScaled($line->unitPrice, 4), 4);
            $net = $amount - Money::percentOf($amount, $line->discountPercent);

            if ($rate === 0 && $natura === null) {
                $warnings[] = (string) __('tax.warnings.zero_rate_without_natura', ['line' => $index + 1]);
            }

            $lines[] = ['net' => $net, 'rate' => $rate, 'natura' => $natura, 'fund' => $line->fundApplies, 'withholding' => $line->withholdingApplies];
        }

        if ($input->splitPayment && ! $this->hasTaxedLine($lines)) {
            throw new FiscalRuleException('split_payment_requires_vat', 'split_payment');
        }

        $net = array_sum(array_column($lines, 'net'));

        // 1. Cassa previdenziale on the fund-eligible lines.
        [$fundBase, $fund, $fundRate, $fundNatura] = $this->fund($input, $lines, $forfettario);

        // 2. VAT summary per (rate, natura); the cassa is taxed like a line.
        $vatSummary = $this->vatSummary($input, $lines, $fund, $fundRate, $fundNatura);
        $vat = array_sum(array_column($vatSummary, 'tax'));

        // 3. Ritenuta d'acconto.
        [$withholdingBase, $withholding] = $this->withholding($input, $lines, $fund);

        // 4. ENASARCO on the commissions, capped by the annual ceiling.
        [$enasarcoCommissions, $enasarcoTaxable, $enasarco] = $this->enasarco($input, $lines);

        // 5. Virtual stamp duty on the amounts without VAT.
        $stampDutyBase = $this->stampDutyBase($lines, $fund, $fundNatura);
        $stampDutyApplied = $stampDutyBase > Money::toCents((string) config('italian_tax.stamp_duty.threshold', '77.47'));
        $stampDuty = $stampDutyApplied ? Money::toCents((string) config('italian_tax.stamp_duty.amount', '2.00')) : 0;
        $stampDutyCharged = $stampDutyApplied && $input->stampDutyChargedToCustomer;

        $gross = $net + $fund + $vat + ($stampDutyCharged ? $stampDuty : 0);

        // 6. Split payment: the public administration pays the VAT directly to the treasury.
        $splitPaymentVat = $input->splitPayment ? $vat : 0;

        if ($input->splitPayment) {
            $warnings[] = (string) __('tax.warnings.split_payment_customer');
        }

        $due = $gross - $withholding - $enasarco - $splitPaymentVat;

        /** @var list<string> $legalNotices */
        $legalNotices = $forfettario ? array_values((array) config('italian_tax.forfettario.legal_notices', [])) : [];

        return new FiscalResult(
            input: $input,
            lines: array_map(fn (array $line): array => ['net' => $line['net'], 'rate' => $line['rate'], 'natura' => $line['natura']], $lines),
            net: $net,
            fundBase: $fundBase,
            fund: $fund,
            fundRate: $fundRate,
            fundNatura: $fundNatura,
            vatSummary: $vatSummary,
            vat: $vat,
            stampDutyBase: $stampDutyBase,
            stampDutyApplied: $stampDutyApplied,
            stampDuty: $stampDuty,
            stampDutyChargedToCustomer: $stampDutyCharged,
            gross: $gross,
            withholdingBase: $withholdingBase,
            withholding: $withholding,
            enasarcoCommissions: $enasarcoCommissions,
            enasarcoTaxable: $enasarcoTaxable,
            enasarco: $enasarco,
            splitPaymentVat: $splitPaymentVat,
            due: $due,
            legalNotices: $legalNotices,
            warnings: $warnings,
        );
    }

    /**
     * Amount a customer is expected to pay for a document computed elsewhere (module invoicing flow).
     */
    public static function receivableCents(int $grossCents, int $vatCents, bool $splitPayment, int $withholdingCents = 0): int
    {
        return $grossCents - ($splitPayment ? $vatCents : 0) - $withholdingCents;
    }

    private function validate(FiscalInput $input, bool $forfettario): void
    {
        if ($forfettario) {
            if ($input->withholding !== null) {
                throw new FiscalRuleException('forfettario_incompatible', 'withholding');
            }

            if ($input->splitPayment) {
                throw new FiscalRuleException('forfettario_incompatible', 'split_payment');
            }

            if ($input->reverseCharge) {
                throw new FiscalRuleException('forfettario_incompatible', 'reverse_charge');
            }
        }

        if ($input->splitPayment && $input->reverseCharge) {
            throw new FiscalRuleException('split_payment_incompatible', 'split_payment');
        }

        $validCodes = (array) config('italian_tax.natura_codes', []);

        foreach ($input->lines as $index => $line) {
            if ($line->natura !== null && ! in_array($line->natura, $validCodes, true)) {
                throw new FiscalRuleException('invalid_natura', "items.{$index}.natura", ['natura' => $line->natura]);
            }
        }

        if ($input->fund?->natura !== null && ! in_array($input->fund->natura, $validCodes, true)) {
            throw new FiscalRuleException('invalid_natura', 'fund.natura', ['natura' => $input->fund->natura]);
        }
    }

    private function lineNatura(FiscalLine $line, FiscalInput $input, bool $forfettario): ?string
    {
        if ($forfettario) {
            return (string) config('italian_tax.forfettario.natura', 'N2.2');
        }

        if ($line->natura !== null) {
            return $line->natura;
        }

        return $input->reverseCharge ? (string) config('italian_tax.reverse_charge.natura', 'N6.9') : null;
    }

    /**
     * @param  list<array{net: int, rate: int, natura: string|null, fund: bool, withholding: bool}>  $lines
     */
    private function hasTaxedLine(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['rate'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fund base, amount, VAT rate and natura. Without an explicit VAT setting the fund follows the highest rate of
     * the fund-eligible lines (or the natura of the first one when none carries VAT).
     *
     * @param  list<array{net: int, rate: int, natura: string|null, fund: bool, withholding: bool}>  $lines
     * @return array{0: int, 1: int, 2: int, 3: string|null}
     */
    private function fund(FiscalInput $input, array $lines, bool $forfettario): array
    {
        if ($input->fund === null) {
            return [0, 0, 0, null];
        }

        $eligible = array_values(array_filter($lines, fn (array $line): bool => $line['fund']));
        $base = array_sum(array_column($eligible, 'net'));
        $amount = Money::percentOf($base, $input->fund->percent);

        if ($forfettario) {
            return [$base, $amount, 0, (string) config('italian_tax.forfettario.natura', 'N2.2')];
        }

        if ($input->fund->natura !== null) {
            return [$base, $amount, 0, $input->fund->natura];
        }

        if ($input->fund->vatRate !== null) {
            return [$base, $amount, Money::toScaled($input->fund->vatRate, 2), null];
        }

        if ($input->reverseCharge) {
            return [$base, $amount, 0, (string) config('italian_tax.reverse_charge.natura', 'N6.9')];
        }

        $highestRate = $eligible === [] ? 0 : max(array_column($eligible, 'rate'));

        if ($highestRate > 0) {
            return [$base, $amount, $highestRate, null];
        }

        return [$base, $amount, 0, $eligible[0]['natura'] ?? null];
    }

    /**
     * @param  list<array{net: int, rate: int, natura: string|null, fund: bool, withholding: bool}>  $lines
     * @return list<array{rate: int, natura: string|null, taxable: int, tax: int, payability: string|null}>
     */
    private function vatSummary(FiscalInput $input, array $lines, int $fund, int $fundRate, ?string $fundNatura): array
    {
        $rows = [];

        $entries = array_map(fn (array $line): array => [$line['rate'], $line['natura'], $line['net']], $lines);

        if ($fund !== 0) {
            $entries[] = [$fundRate, $fundNatura, $fund];
        }

        foreach ($entries as [$rate, $natura, $amount]) {
            $key = $rate.'|'.($natura ?? '');
            $rows[$key] ??= ['rate' => $rate, 'natura' => $natura, 'taxable' => 0];
            $rows[$key]['taxable'] += $amount;
        }

        $payability = $input->splitPayment ? 'S' : (in_array($input->vatPayability, ['I', 'D'], true) ? $input->vatPayability : 'I');

        return array_values(array_map(fn (array $row): array => [
            'rate' => $row['rate'],
            'natura' => $row['natura'],
            'taxable' => $row['taxable'],
            'tax' => Money::percentOfBasisPoints($row['taxable'], $row['rate']),
            'payability' => $row['natura'] === null ? $payability : null,
        ], $rows));
    }

    /**
     * @param  list<array{net: int, rate: int, natura: string|null, fund: bool, withholding: bool}>  $lines
     * @return array{0: int, 1: int}
     */
    private function withholding(FiscalInput $input, array $lines, int $fund): array
    {
        $settings = $input->withholding;

        if ($settings === null) {
            return [0, 0];
        }

        $includesFund = $settings->includesFund || ($input->fund?->withholdingApplies ?? false);
        $eligible = array_sum(array_column(array_filter($lines, fn (array $line): bool => $line['withholding']), 'net'));
        $base = Money::percentOf($eligible + ($includesFund ? $fund : 0), $settings->taxablePercent);

        return [$base, Money::percentOf($base, $settings->rate)];
    }

    /**
     * @param  list<array{net: int, rate: int, natura: string|null, fund: bool, withholding: bool}>  $lines
     * @return array{0: int, 1: int, 2: int}
     */
    private function enasarco(FiscalInput $input, array $lines): array
    {
        $settings = $input->enasarco;

        if ($settings === null) {
            return [0, 0, 0];
        }

        $commissions = array_sum(array_column(array_filter($lines, fn (array $line): bool => $line['withholding']), 'net'));
        $remainingCap = max(0, Money::toCents($settings->annualMaximumCommission) - Money::toCents($settings->ytdCommissions));
        $taxable = max(0, min($commissions, $remainingCap));

        return [$commissions, $taxable, Money::percentOf($taxable, $settings->rate)];
    }

    /**
     * Sum of the amounts (lines and fund) whose natura is subject to stamp duty.
     *
     * @param  list<array{net: int, rate: int, natura: string|null, fund: bool, withholding: bool}>  $lines
     */
    private function stampDutyBase(array $lines, int $fund, ?string $fundNatura): int
    {
        $codes = (array) config('italian_tax.stamp_duty.natura_codes', []);
        $base = 0;

        foreach ($lines as $line) {
            if ($line['natura'] !== null && in_array($line['natura'], $codes, true)) {
                $base += $line['net'];
            }
        }

        if ($fundNatura !== null && in_array($fundNatura, $codes, true)) {
            $base += $fund;
        }

        return $base;
    }
}
