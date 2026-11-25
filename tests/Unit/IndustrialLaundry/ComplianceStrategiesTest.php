<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Compliance\BillingContext;
use Modules\IndustrialLaundry\Compliance\BillingDecoration;
use Modules\IndustrialLaundry\Compliance\ChItLaundryBillingStrategy;
use Modules\IndustrialLaundry\Compliance\ComplianceFinding;
use Modules\IndustrialLaundry\Compliance\ComplianceStrategyRegistry;
use Modules\IndustrialLaundry\Compliance\En14065RabcAndPpeWashLimitStrategy;
use Modules\IndustrialLaundry\Compliance\WashBatchMeasurements;
use Modules\IndustrialLaundry\Domain\Enums\FindingSeverity;
use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\WashProgram;

/**
 * @param  array<string, mixed>  $attributes
 */
function ilrGarmentModel(array $attributes = []): Garment
{
    return new Garment($attributes + [
        'epc_uid_rfid_hex' => 'E28011600000000000000001', 'article_type' => 'ppe_safety_garment', 'max_certified_wash_cycles_limit' => 50,
        'ppe_category' => 2, 'ppe_certification_standard' => 'EN ISO 20471', 'wash_cycles_count' => 0,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ilrProgram(array $attributes = []): WashProgram
{
    return new WashProgram($attributes + [
        'code' => 'THERMAL_71_3MIN', 'is_thermal' => true, 'is_chemical' => false, 'disinfects' => true, 'target_temperature_c' => '71.00', 'min_hold_minutes' => 3, 'min_chemical_dosing_ml_per_kg' => null,
    ]);
}

/**
 * @return list<string>
 */
function ilrCodes(array $findings): array
{
    return array_map(static fn (ComplianceFinding $finding): string => $finding->code.':'.$finding->severity->value, $findings);
}

it('blocks the re-issue of a category II or III PPE garment that reached its certified wash limit', function (): void {
    $strategy = new En14065RabcAndPpeWashLimitStrategy;

    expect(ilrCodes($strategy->evaluateGarment(ilrGarmentModel(), 50)))->toBe(['ppe_wash_limit_reached:blocking'])
        ->and(ilrCodes($strategy->evaluateGarment(ilrGarmentModel(['ppe_category' => 3]), 51)))->toBe(['ppe_wash_limit_reached:blocking'])
        ->and(ilrCodes($strategy->evaluateGarment(ilrGarmentModel(['ppe_category' => null]), 50)))->toBe(['ppe_wash_limit_reached:blocking']);
});

it('only warns for a category I PPE garment at its wash limit', function (): void {
    expect(ilrCodes((new En14065RabcAndPpeWashLimitStrategy)->evaluateGarment(ilrGarmentModel(['ppe_category' => 1]), 50)))->toBe(['ppe_wash_limit_reached:warning']);
});

it('warns from the configured share of the wash limit and stays silent before it', function (): void {
    $strategy = new En14065RabcAndPpeWashLimitStrategy;
    $near = $strategy->evaluateGarment(ilrGarmentModel(), 45);

    expect(ilrCodes($near))->toBe(['ppe_wash_limit_near:warning'])
        ->and($near[0]->params['remaining'])->toBe(5)
        ->and($strategy->evaluateGarment(ilrGarmentModel(), 44))->toBe([]);

    config(['industrial_laundry.ppe.warning_ratio' => '0.5']);

    expect(ilrCodes($strategy->evaluateGarment(ilrGarmentModel(), 25)))->toBe(['ppe_wash_limit_near:warning']);
});

it('ignores garments without a wash limit or that are not protective equipment', function (): void {
    $strategy = new En14065RabcAndPpeWashLimitStrategy;

    expect($strategy->evaluateGarment(ilrGarmentModel(['max_certified_wash_cycles_limit' => null]), 500))->toBe([])
        ->and($strategy->evaluateGarment(ilrGarmentModel(['article_type' => 'flat_linen', 'ppe_certification_standard' => null]), 500))->toBe([])
        ->and(ilrCodes($strategy->evaluateGarment(ilrGarmentModel(['article_type' => 'personal_workwear_uniform']), 50)))->toBe(['ppe_wash_limit_reached:blocking']);
});

it('certifies a batch that reached the temperature for the time and stayed under the microbial ceiling', function (): void {
    $strategy = new En14065RabcAndPpeWashLimitStrategy;

    expect($strategy->evaluateWashBatch(new WashBatchMeasurements(ilrProgram(), RabcRiskZone::Contaminated, '72.50', 4, null, '3.00')))->toBe([])
        ->and($strategy->evaluateWashBatch(new WashBatchMeasurements(ilrProgram(), RabcRiskZone::Contaminated, '71.00', 3, null, '20.00')))->toBe([]);
});

it('reports every disinfection target a batch missed', function (): void {
    $strategy = new En14065RabcAndPpeWashLimitStrategy;

    expect(ilrCodes($strategy->evaluateWashBatch(new WashBatchMeasurements(ilrProgram(), RabcRiskZone::Contaminated, '65.00', 2, null, null))))
        ->toBe(['rabc_temperature_below_target:blocking', 'rabc_hold_time_below_target:blocking'])
        ->and(ilrCodes($strategy->evaluateWashBatch(new WashBatchMeasurements(ilrProgram(), RabcRiskZone::Contaminated, null, null, null, null))))
        ->toBe(['rabc_temperature_below_target:blocking', 'rabc_hold_time_below_target:blocking']);
});

it('checks the chemical dosing of a chemical program and the microbial ceiling', function (): void {
    $strategy = new En14065RabcAndPpeWashLimitStrategy;
    $chemical = ilrProgram(['code' => 'CHEMICAL_40_PAA', 'is_thermal' => false, 'is_chemical' => true, 'target_temperature_c' => '40.00', 'min_hold_minutes' => 10, 'min_chemical_dosing_ml_per_kg' => '35.000']);

    expect(ilrCodes($strategy->evaluateWashBatch(new WashBatchMeasurements($chemical, RabcRiskZone::Protected, null, null, '30.000', '25.00'))))
        ->toBe(['rabc_chemical_dosing_below_target:blocking', 'rabc_microbial_count_exceeded:blocking'])
        ->and($strategy->evaluateWashBatch(new WashBatchMeasurements($chemical, RabcRiskZone::Protected, null, null, '35.000', '20.00')))->toBe([]);
});

it('requires a disinfecting program for contaminated and protected linen but not for standard linen', function (): void {
    $strategy = new En14065RabcAndPpeWashLimitStrategy;
    $plain = ilrProgram(['code' => 'STANDARD_60', 'disinfects' => false, 'target_temperature_c' => '60.00', 'min_hold_minutes' => 10]);

    expect(ilrCodes($strategy->evaluateWashBatch(new WashBatchMeasurements($plain, RabcRiskZone::Contaminated, '60.00', 10, null, null))))->toBe(['rabc_program_not_disinfecting:blocking'])
        ->and($strategy->evaluateWashBatch(new WashBatchMeasurements($plain, RabcRiskZone::Standard, null, null, null, null)))->toBe([]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function ilrBillingContext(array $attributes, string $netKg = '1000', array $rollCages = ['out' => 6, 'back' => 5, 'at_customer' => 2]): BillingContext
{
    $contract = new ParContract($attributes + ['billing_model' => 'per_piece_washed', 'kg_basis' => 'clean', 'jurisdiction' => 'IT', 'currency' => 'EUR']);

    return new BillingContext($contract, Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'), $netKg, 500, ['DDT-2028-0001', 'DDT-2028-0002'], $rollCages);
}

it('decorates the Italian bill as a deferred TD24 invoice quoting the delivery notes', function (): void {
    $decoration = (new ChItLaundryBillingStrategy)->decorateBilling(ilrBillingContext(['jurisdiction' => 'IT']), new BillingDecoration);

    expect($decoration->documentType)->toBe('TD24')
        ->and($decoration->paymentFlavour)->toBe('sdi_deferred')
        ->and($decoration->notes[0])->toBe(['key' => 'ddt_references_it', 'params' => ['numbers' => 'DDT-2028-0001, DDT-2028-0002']])
        ->and($decoration->notes[1]['key'])->toBe('returnable_containers')
        ->and($decoration->report['document'])->toBe('TD24')
        ->and($decoration->report['returnable_containers'])->toBe(['out' => 6, 'back' => 5, 'at_customer' => 2]);
});

it('decorates the Swiss bill with the QR-bill and no fiscal document type', function (): void {
    $decoration = (new ChItLaundryBillingStrategy)->decorateBilling(ilrBillingContext(['jurisdiction' => 'CH', 'currency' => 'CHF']), new BillingDecoration);

    expect($decoration->documentType)->toBeNull()
        ->and($decoration->paymentFlavour)->toBe('qr_bill')
        ->and($decoration->notes[0]['key'])->toBe('ddt_references_ch')
        ->and($decoration->report['document'])->toBe('QR-Bill');
});

it('reports the industrial water discharge per kg of linen treated', function (): void {
    $decoration = (new ChItLaundryBillingStrategy)->decorateBilling(ilrBillingContext([], '1000.000'), new BillingDecoration);

    // 1000 kg x 8 l/kg = 8000 l = 8 m3 (the configured rate).
    expect($decoration->report['water_discharge'])->toBe([
        'treated_net_kg' => '1000.000', 'litres_per_kg' => '8', 'discharged_litres' => '8000.000', 'discharged_m3' => '8.000',
    ]);

    config(['industrial_laundry.discharge.water_litres_per_kg' => '6.5']);
    $again = (new ChItLaundryBillingStrategy)->decorateBilling(ilrBillingContext([], '200'), new BillingDecoration);

    expect($again->report['water_discharge']['discharged_m3'])->toBe('1.300');
});

it('leaves out the delivery note note when the period has none', function (): void {
    $context = new BillingContext(new ParContract(['jurisdiction' => 'IT', 'billing_model' => 'per_piece_washed', 'kg_basis' => 'clean']), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'), '0', 0, [], ['out' => 0, 'back' => 0, 'at_customer' => 0]);

    $decoration = (new ChItLaundryBillingStrategy)->decorateBilling($context, new BillingDecoration);

    expect(array_column($decoration->notes, 'key'))->toBe(['returnable_containers']);
});

it('runs every registered strategy and merges what they answer', function (): void {
    $registry = new ComplianceStrategyRegistry([new En14065RabcAndPpeWashLimitStrategy, new ChItLaundryBillingStrategy]);

    $garment = $registry->garmentFindings(ilrGarmentModel(), 50);
    $batch = $registry->batchFindings(new WashBatchMeasurements(ilrProgram(), RabcRiskZone::Contaminated, '60.00', 3, null, null));
    $billing = $registry->decorateBilling(ilrBillingContext(['jurisdiction' => 'IT']));

    expect(ComplianceStrategyRegistry::blocks($garment))->toBeTrue()
        ->and(ComplianceStrategyRegistry::blocks($batch))->toBeTrue()
        ->and(ComplianceStrategyRegistry::blocks([new ComplianceFinding('ppe_wash_limit_near', FindingSeverity::Warning)]))->toBeFalse()
        ->and(ComplianceStrategyRegistry::blocks([]))->toBeFalse()
        ->and($billing->documentType)->toBe('TD24')
        ->and($registry->garmentFindings(ilrGarmentModel(['article_type' => 'flat_linen', 'ppe_certification_standard' => null]), 999))->toBe([]);
});

it('exposes the key of each strategy', function (): void {
    expect((new En14065RabcAndPpeWashLimitStrategy)->key())->toBe('en14065_rabc_ppe')
        ->and((new ChItLaundryBillingStrategy)->key())->toBe('ch_it_laundry_billing');
});
