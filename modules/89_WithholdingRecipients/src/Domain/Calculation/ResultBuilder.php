<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Mutable accumulator the calculators fill, so each of them only states the figures it owns. The net payable amount and
 * the cost for the company are derived here, once, from those figures.
 */
final class ResultBuilder
{
    public Money $flatRateDeduction;

    public Money $taxableBase;

    public string $withholdingRate = '0';

    public Money $withholding;

    public Money $inpsBase;

    public Money $inpsTotal;

    public Money $inpsWorker;

    public Money $inpsCompany;

    public Money $stampDuty;

    public Money $enasarcoBase;

    public Money $enasarcoAgent;

    public Money $enasarcoCompany;

    public Money $firrAccrual;

    public Money $avsBase;

    public Money $avsEmployee;

    public Money $avsEmployer;

    public Money $acquisitionExpenses;

    public Money $sourceTax;

    public Money $nonTaxable;

    public ?string $nonTaxableCode = null;

    public ?string $cuCausal = null;

    /** @var list<array{key: string, current: string, limit: string, percent: float}> */
    public array $thresholds = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $notes = [];

    public function __construct(private readonly CompensationInput $input)
    {
        $zero = Money::zero();
        $this->flatRateDeduction = $zero;
        $this->taxableBase = $input->gross;
        $this->withholding = $zero;
        $this->inpsBase = $zero;
        $this->inpsTotal = $zero;
        $this->inpsWorker = $zero;
        $this->inpsCompany = $zero;
        $this->stampDuty = $zero;
        $this->enasarcoBase = $zero;
        $this->enasarcoAgent = $zero;
        $this->enasarcoCompany = $zero;
        $this->firrAccrual = $zero;
        $this->avsBase = $zero;
        $this->avsEmployee = $zero;
        $this->avsEmployer = $zero;
        $this->acquisitionExpenses = $zero;
        $this->sourceTax = $zero;
        $this->nonTaxable = $zero;
    }

    public function threshold(string $key, Money $current, Money $limit): void
    {
        $percent = $limit->cents === 0 ? 0.0 : min(100.0, round($current->cents * 100 / $limit->cents, 2));
        $this->thresholds[] = ['key' => $key, 'current' => $current->toDecimal(), 'limit' => $limit->toDecimal(), 'percent' => $percent];
    }

    public function build(): CompensationResult
    {
        $gross = $this->input->gross;
        $net = $gross
            ->minus($this->withholding)
            ->minus($this->inpsWorker)
            ->minus($this->stampDuty)
            ->minus($this->enasarcoAgent)
            ->minus($this->avsEmployee)
            ->minus($this->sourceTax);
        $cost = $gross
            ->plus($this->inpsCompany)
            ->plus($this->enasarcoCompany)
            ->plus($this->firrAccrual)
            ->plus($this->avsEmployer);

        return new CompensationResult(
            $this->input->regime->currency(),
            $gross,
            $this->flatRateDeduction,
            $this->taxableBase,
            $this->withholdingRate,
            $this->withholding,
            $this->inpsBase,
            $this->inpsTotal,
            $this->inpsWorker,
            $this->inpsCompany,
            $this->stampDuty,
            $this->enasarcoBase,
            $this->enasarcoAgent,
            $this->enasarcoCompany,
            $this->firrAccrual,
            $this->avsBase,
            $this->avsEmployee,
            $this->avsEmployer,
            $this->acquisitionExpenses,
            $this->sourceTax,
            $this->nonTaxable,
            $this->nonTaxableCode,
            $this->cuCausal,
            $net,
            $cost,
            $this->thresholds,
            $this->warnings,
            $this->notes,
        );
    }
}
