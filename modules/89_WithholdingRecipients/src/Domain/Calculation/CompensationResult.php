<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Outcome of the calculation of one compensation. Every figure is a Money; thresholds and warnings are plain data the
 * simulator and the settlement screen render with translated labels.
 */
final readonly class CompensationResult
{
    /**
     * @param  list<array{key: string, current: string, limit: string, percent: float}>  $thresholds  progress against statutory limits, after this payment
     * @param  list<string>  $warnings  codes (translation keys under withholdingRecipients.warning.*)
     * @param  list<string>  $notes  informational codes (withholdingRecipients.note.*)
     */
    public function __construct(
        public string $currency,
        public Money $gross,
        public Money $flatRateDeduction,
        public Money $taxableBase,
        public string $withholdingRate,
        public Money $withholding,
        public Money $inpsBase,
        public Money $inpsTotal,
        public Money $inpsWorker,
        public Money $inpsCompany,
        public Money $stampDuty,
        public Money $enasarcoBase,
        public Money $enasarcoAgent,
        public Money $enasarcoCompany,
        public Money $firrAccrual,
        public Money $avsBase,
        public Money $avsEmployee,
        public Money $avsEmployer,
        public Money $acquisitionExpenses,
        public Money $sourceTax,
        public Money $nonTaxable,
        public ?string $nonTaxableCode,
        public ?string $cuCausal,
        public Money $netPayable,
        public Money $companyCost,
        public array $thresholds = [],
        public array $warnings = [],
        public array $notes = [],
    ) {}

    /**
     * Effective withholding rate (IRPEF withholding or source tax) on the gross amount, in percent with two decimals.
     */
    public function effectiveRate(): string
    {
        if ($this->gross->cents === 0) {
            return '0.00';
        }

        return number_format(($this->withholding->cents + $this->sourceTax->cents) * 100 / $this->gross->cents, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'gross_amount' => $this->gross->toDecimal(),
            'flat_rate_deduction' => $this->flatRateDeduction->toDecimal(),
            'taxable_base' => $this->taxableBase->toDecimal(),
            'withholding_rate' => $this->withholdingRate,
            'effective_withholding_rate' => $this->effectiveRate(),
            'irpef_withholding' => $this->withholding->toDecimal(),
            'inps_base' => $this->inpsBase->toDecimal(),
            'inps_total' => $this->inpsTotal->toDecimal(),
            'inps_worker' => $this->inpsWorker->toDecimal(),
            'inps_company' => $this->inpsCompany->toDecimal(),
            'stamp_duty' => $this->stampDuty->toDecimal(),
            'enasarco_base' => $this->enasarcoBase->toDecimal(),
            'enasarco_agent' => $this->enasarcoAgent->toDecimal(),
            'enasarco_company' => $this->enasarcoCompany->toDecimal(),
            'firr_accrual' => $this->firrAccrual->toDecimal(),
            'avs_base' => $this->avsBase->toDecimal(),
            'avs_employee' => $this->avsEmployee->toDecimal(),
            'avs_employer' => $this->avsEmployer->toDecimal(),
            'acquisition_expenses' => $this->acquisitionExpenses->toDecimal(),
            'source_tax' => $this->sourceTax->toDecimal(),
            'non_taxable_amount' => $this->nonTaxable->toDecimal(),
            'non_taxable_code' => $this->nonTaxableCode,
            'cu_causal' => $this->cuCausal,
            'net_payable' => $this->netPayable->toDecimal(),
            'company_cost' => $this->companyCost->toDecimal(),
            'thresholds' => $this->thresholds,
            'warnings' => $this->warnings,
            'notes' => $this->notes,
        ];
    }
}
