<?php

declare(strict_types=1);

use Modules\DownPayments\Domain\Enums\CalculationMode;
use Modules\DownPayments\Domain\Enums\InstallmentTrigger;
use Modules\DownPayments\Domain\Enums\InstrumentType;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\DownPayments\Services\InstallmentPlanService;
use Modules\DownPayments\Services\MultiVatProportionalAllocator;

/**
 * The installment plan wizard: every installment is split over what the earlier ones left of each VAT rate, so the plan adds up to the
 * contract to the cent and can never state more than the source holds.
 */
beforeEach(function (): void {
    $this->plan = new InstallmentPlanService(new MultiVatProportionalAllocator);
    // 10 x 100.00 at 22% and 5 x 40.00 at 10%: net 1200.00, gross 1440.00
    $this->bases = ['22.00' => '1000.00', '10.00' => '200.00'];
});

it('computes the 30/40/30 template to exactly the contract total, the last installment taking the rest', function (): void {
    $rows = $this->plan->compute($this->bases, $this->plan->presets()[InstallmentPlanService::TEMPLATE_30_40_30]);

    expect($rows)->toHaveCount(3)
        ->and(array_sum(array_map(static fn (array $row): float => (float) $row['amount_net'], $rows)))->toBe(1200.0)
        ->and(array_sum(array_map(static fn (array $row): float => (float) $row['amount_gross'], $rows)))->toBe(1440.0)
        ->and($rows[0]['amount_net'])->toBe('360.00')
        ->and($rows[1]['amount_net'])->toBe('480.00')
        ->and($rows[2]['amount_net'])->toBe('360.00');

    $perRate = ['22.00' => '0', '10.00' => '0'];

    foreach ($rows as $row) {
        foreach ($row['vat_breakdown'] as $slice) {
            $perRate[$slice['vat_rate']] = bcadd($perRate[$slice['vat_rate']], $slice['net'], 2);
        }
    }

    expect($perRate['22.00'])->toBe('1000.00')->and($perRate['10.00'])->toBe('200.00');
});

it('splits every installment over the VAT rates in proportion to the net of the source', function (): void {
    $rows = $this->plan->compute($this->bases, $this->plan->presets()[InstallmentPlanService::TEMPLATE_50_50]);
    $first = collect($rows[0]['vat_breakdown'])->keyBy('vat_rate');

    expect($first['22.00']['net'])->toBe('500.00')
        ->and($first['10.00']['net'])->toBe('100.00')
        ->and($first['22.00']['vat'])->toBe('110.00')
        ->and($first['10.00']['vat'])->toBe('10.00');
});

it('states a fixed gross installment to the cent', function (): void {
    $rows = $this->plan->compute($this->bases, [
        ['trigger_type' => InstallmentTrigger::CustomDate->value, 'calculation_mode' => CalculationMode::FixedGross->value, 'amount' => '720.00', 'instrument_type' => InstrumentType::AdvanceInvoiceImmediate->value, 'milestone_label' => 'Half'],
    ]);

    expect($rows[0]['amount_gross'])->toBe('720.00')->and($rows[0]['rounding_drift'])->toBe('0.00')->and($rows[0]['milestone_label'])->toBe('Half');
});

it('treats deposits as outside the VAT scope: net equals gross and no VAT is stated', function (): void {
    $rows = $this->plan->compute($this->bases, $this->plan->presets()[InstallmentPlanService::TEMPLATE_CAPARRA_BALANCE]);

    expect($rows[0]['instrument_type'])->toBe(InstrumentType::EarnestMoneyDeposit->value)
        ->and($rows[0]['amount_gross'])->toBe('144.00')
        ->and($rows[0]['amount_net'])->toBe('144.00')
        ->and($rows[0]['amount_vat'])->toBe('0.00')
        ->and($rows[0]['vat_breakdown'])->toBe([]);
});

it('does not count a security deposit as part of the price', function (): void {
    $rows = $this->plan->compute($this->bases, [
        ['trigger_type' => InstallmentTrigger::OnOrderConfirmation->value, 'calculation_mode' => CalculationMode::Percentage->value, 'percentage' => '100', 'instrument_type' => InstrumentType::AdvanceInvoiceImmediate->value],
        ['trigger_type' => InstallmentTrigger::OnOrderConfirmation->value, 'calculation_mode' => CalculationMode::Percentage->value, 'percentage' => '10', 'instrument_type' => InstrumentType::SecurityDeposit->value],
    ]);

    expect($rows)->toHaveCount(2);
});

it('refuses a plan that states more than the source holds', function (): void {
    expect(fn () => $this->plan->compute($this->bases, [
        ['trigger_type' => InstallmentTrigger::OnOrderConfirmation->value, 'calculation_mode' => CalculationMode::Percentage->value, 'percentage' => '60', 'instrument_type' => InstrumentType::AdvanceInvoiceImmediate->value],
        ['trigger_type' => InstallmentTrigger::OnMaterialDelivery->value, 'calculation_mode' => CalculationMode::Percentage->value, 'percentage' => '60', 'instrument_type' => InstrumentType::AdvanceInvoiceImmediate->value],
    ]))->toThrow(DownPaymentException::class);
});

it('refuses an empty plan and malformed installments', function (): void {
    expect(fn () => $this->plan->compute($this->bases, []))->toThrow(DownPaymentException::class);
    expect(fn () => $this->plan->compute($this->bases, [['trigger_type' => 'nope', 'calculation_mode' => 'percentage', 'percentage' => '10', 'instrument_type' => InstrumentType::AdvanceInvoiceImmediate->value]]))->toThrow(DownPaymentException::class);
    expect(fn () => $this->plan->compute($this->bases, [['trigger_type' => InstallmentTrigger::CustomDate->value, 'calculation_mode' => 'percentage', 'percentage' => '0', 'instrument_type' => InstrumentType::AdvanceInvoiceImmediate->value]]))->toThrow(DownPaymentException::class);
});

it('carries the error code of a refused rule', function (): void {
    try {
        $this->plan->compute($this->bases, []);
    } catch (DownPaymentException $e) {
        expect($e->errorCode())->toBe('plan_requires_installments')->and($e->status())->toBe(422);
    }
});
