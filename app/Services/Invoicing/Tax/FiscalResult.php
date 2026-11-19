<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Support\Money;

/**
 * Every fiscal figure of an invoice, in integer cents (rates in hundredths of a percent: 2200 = 22.00%).
 *
 * `gross` is ImportoTotaleDocumento (net + fund + VAT + stamp duty charged to the customer); `due` is ImportoPagamento
 * (gross - withholding - ENASARCO - split-payment VAT).
 */
final readonly class FiscalResult
{
    /**
     * @param  list<array{net: int, rate: int, natura: string|null}>  $lines
     * @param  list<array{rate: int, natura: string|null, taxable: int, tax: int, payability: string|null}>  $vatSummary
     * @param  list<string>  $legalNotices
     * @param  list<string>  $warnings
     */
    public function __construct(
        public FiscalInput $input,
        public array $lines,
        public int $net,
        public int $fundBase,
        public int $fund,
        public int $fundRate,
        public ?string $fundNatura,
        public array $vatSummary,
        public int $vat,
        public int $stampDutyBase,
        public bool $stampDutyApplied,
        public int $stampDuty,
        public bool $stampDutyChargedToCustomer,
        public int $gross,
        public int $withholdingBase,
        public int $withholding,
        public int $enasarcoCommissions,
        public int $enasarcoTaxable,
        public int $enasarco,
        public int $splitPaymentVat,
        public int $due,
        public array $legalNotices,
        public array $warnings,
    ) {}

    /**
     * Sum of the taxable amounts of the summary rows that carry VAT (rate above zero).
     */
    public function taxableVat(): int
    {
        return array_sum(array_map(fn (array $row): int => $row['rate'] > 0 ? $row['taxable'] : 0, $this->vatSummary));
    }

    /**
     * VAT summary as stored on the invoice and returned by the API (decimal strings).
     *
     * @return list<array{rate: string, natura: string|null, taxable: string, tax: string, payability: string|null}>
     */
    public function vatSummaryArray(): array
    {
        return array_map(fn (array $row): array => [
            'rate' => Money::fromScaled($row['rate'], 2),
            'natura' => $row['natura'],
            'taxable' => Money::fromCents($row['taxable']),
            'tax' => Money::fromCents($row['tax']),
            'payability' => $row['payability'],
        ], $this->vatSummary);
    }

    /**
     * API representation used by the fiscal preview endpoint (amounts as decimal strings with two decimals).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fund = $this->input->fund;
        $withholding = $this->input->withholding;
        $enasarco = $this->input->enasarco;

        return [
            'totals' => [
                'net' => Money::fromCents($this->net),
                'fund' => Money::fromCents($this->fund),
                'taxable_vat' => Money::fromCents($this->taxableVat()),
                'vat' => Money::fromCents($this->vat),
                'stamp_duty' => Money::fromCents($this->stampDutyChargedToCustomer ? $this->stampDuty : 0),
                'gross' => Money::fromCents($this->gross),
                'withholding' => Money::fromCents($this->withholding),
                'enasarco' => Money::fromCents($this->enasarco),
                'split_payment_vat' => Money::fromCents($this->splitPaymentVat),
                'due' => Money::fromCents($this->due),
            ],
            'vat_summary' => $this->vatSummaryArray(),
            'lines' => array_map(fn (array $line): array => [
                'net' => Money::fromCents($line['net']),
                'tax_rate' => Money::fromScaled($line['rate'], 2),
                'natura' => $line['natura'],
            ], $this->lines),
            'fund' => $fund === null ? null : [
                'type' => $fund->type,
                'percent' => Money::fromScaled(Money::toScaled($fund->percent, 2), 2),
                'base' => Money::fromCents($this->fundBase),
                'amount' => Money::fromCents($this->fund),
                'vat_rate' => Money::fromScaled($this->fundRate, 2),
                'natura' => $this->fundNatura,
                'withholding_applies' => $fund->withholdingApplies,
            ],
            'withholding' => $withholding === null ? null : [
                'type' => $withholding->type,
                'reason' => $withholding->reason,
                'rate' => Money::fromScaled(Money::toScaled($withholding->rate, 2), 2),
                'taxable_percent' => Money::fromScaled(Money::toScaled($withholding->taxablePercent, 2), 2),
                'includes_fund' => $this->withholdingIncludesFund(),
                'base' => Money::fromCents($this->withholdingBase),
                'amount' => Money::fromCents($this->withholding),
            ],
            'enasarco' => $enasarco === null ? null : [
                'rate' => Money::fromScaled(Money::toScaled($enasarco->rate, 2), 2),
                'commissions' => Money::fromCents($this->enasarcoCommissions),
                'contributory_base' => Money::fromCents($this->enasarcoTaxable),
                'amount' => Money::fromCents($this->enasarco),
                'annual_maximum' => Money::fromCents(Money::toCents($enasarco->annualMaximumCommission)),
                'ytd_commissions' => Money::fromCents(Money::toCents($enasarco->ytdCommissions)),
            ],
            'stamp_duty' => [
                'applied' => $this->stampDutyApplied,
                'amount' => Money::fromCents($this->stampDuty),
                'charged_to_customer' => $this->stampDutyChargedToCustomer,
                'exempt_base' => Money::fromCents($this->stampDutyBase),
            ],
            'split_payment' => $this->input->splitPayment,
            'legal_notices' => $this->legalNotices,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Whether the withholding base includes the fund (per invoice flag or fund setting).
     */
    public function withholdingIncludesFund(): bool
    {
        return ($this->input->withholding !== null && $this->input->withholding->includesFund) || ($this->input->fund !== null && $this->input->fund->withholdingApplies);
    }
}
