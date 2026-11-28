<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\BottleFormat;
use Modules\OliveMillAndGrainMill\Domain\Enums\Certification;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Domain\Enums\SettlementMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\TariffMode;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\Services\MolendaCompensationService;
use Modules\OliveMillAndGrainMill\Domain\Services\SianComplianceService;
use Modules\OliveMillAndGrainMill\Domain\Services\SianFileBuilder;
use Modules\OliveMillAndGrainMill\Domain\Services\YieldCalculationService;
use Modules\OliveMillAndGrainMill\Domain\Support\TankComposition;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\AcidityProfile;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\MolendaCalculation;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\SianCuaaIdentifier;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\WeighbridgeMeasurement;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\YieldPercentage;

const OM_THRESHOLDS = [
    'EVO' => ['acidity_max' => 0.8, 'peroxides_max' => 20.0],
    'VERGINE' => ['acidity_max' => 2.0, 'peroxides_max' => 20.0],
];

function omErrorCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (OliveMillException $exception) {
        return $exception->errorCode();
    }

    return null;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function omMolenda(array $overrides = []): MolendaCalculation
{
    $input = array_merge([
        'oliveNetKg' => 5000.0, 'outputKg' => 800.0, 'availableKg' => 800.0, 'tariff' => TariffMode::PerQuintalOlives, 'tariffValue' => 8.0,
        'serviceVatRate' => 22.0, 'mode' => SettlementMode::CashOrBank, 'molendaPercent' => null, 'fixedRetainedKg' => null,
        'oilPricePerKg' => 0.0, 'purchaseVatRate' => 4.0,
    ], $overrides);

    return (new MolendaCompensationService)->calculate(...$input);
}

// --- Weighbridge, density and yield -------------------------------------------------------------------------------------------------------

test('the net weight is the gross weight minus the vehicle tare and the crates', function (): void {
    $measurement = new WeighbridgeMeasurement(5640.0, 400.0, 100, 2.0);

    expect($measurement->netKg())->toBe(5040.0)->and($measurement->totalTareKg())->toBe(600.0);
});

test('a reading with a non positive net weight or negative values is refused', function (): void {
    expect(omErrorCode(fn () => (new WeighbridgeMeasurement(300.0, 200.0, 100, 2.0))->netKg()))->toBe('net_weight_not_positive')
        ->and(omErrorCode(fn () => (new WeighbridgeMeasurement(0.0, 0.0, 0, 0.0))->netKg()))->toBe('weight_invalid')
        ->and(omErrorCode(fn () => (new WeighbridgeMeasurement(1000.0, -1.0, 0, 0.0))->netKg()))->toBe('weight_invalid');
});

test('the oil density converts kilograms to litres and back', function (): void {
    $converter = new OilDensityConverter(0.916);

    expect($converter->toLiters(916.0))->toBe(1000.0)
        ->and($converter->toKg(1000.0))->toBe(916.0)
        ->and(omErrorCode(fn () => new OilDensityConverter(0.0)))->toBe('density_invalid');
});

test('the yield is the oil over the net weight of the olives with two decimals', function (): void {
    expect(YieldPercentage::of(600.0, 4000.0)->value)->toBe(15.0)
        ->and(YieldPercentage::of(123.0, 1000.0)->value)->toBe(12.3)
        ->and(omErrorCode(fn () => YieldPercentage::of(10.0, 0.0)))->toBe('input_weight_not_positive')
        ->and(omErrorCode(fn () => YieldPercentage::of(-1.0, 100.0)))->toBe('output_negative');
});

test('the lab values grade the oil under the EU thresholds', function (): void {
    expect((new AcidityProfile(0.5, 12.0))->qualityClass(OM_THRESHOLDS))->toBe(OilCategory::Evo)
        ->and((new AcidityProfile(0.8, null))->qualityClass(OM_THRESHOLDS))->toBe(OilCategory::Evo)
        ->and((new AcidityProfile(1.2, 10.0))->qualityClass(OM_THRESHOLDS))->toBe(OilCategory::Vergine)
        ->and((new AcidityProfile(2.5, 10.0))->qualityClass(OM_THRESHOLDS))->toBe(OilCategory::Lampante)
        ->and((new AcidityProfile(0.5, 25.0))->qualityClass(OM_THRESHOLDS))->toBe(OilCategory::Lampante)
        ->and((new AcidityProfile(null, 10.0))->qualityClass(OM_THRESHOLDS))->toBeNull();
});

test('a declared category is satisfied only by an equal or better class', function (): void {
    $virgin = new AcidityProfile(1.2, 10.0);

    expect($virgin->satisfies(OilCategory::Vergine, OM_THRESHOLDS))->toBeTrue()
        ->and($virgin->satisfies(OilCategory::Evo, OM_THRESHOLDS))->toBeFalse()
        ->and($virgin->satisfies(OilCategory::Dop, OM_THRESHOLDS))->toBeFalse()
        ->and((new AcidityProfile(0.4, 8.0))->satisfies(OilCategory::Bio, OM_THRESHOLDS))->toBeTrue();
});

test('the cycle evaluation flags cold extraction, volume and a quality below the declared one', function (): void {
    $service = new YieldCalculationService(new OilDensityConverter(0.916), 27.0, OM_THRESHOLDS);

    $cold = $service->evaluate(4000.0, 916.0, 26.5, new AcidityProfile(0.4, 9.0), Certification::Evo, true);
    $warm = $service->evaluate(4000.0, 916.0, 28.0, new AcidityProfile(1.0, 9.0), Certification::Dop, true);
    $grain = $service->evaluate(1000.0, 800.0, null, null, Certification::Convenzionale, false);

    expect($cold['cold_extraction'])->toBeTrue()
        ->and($cold['yield_percent'])->toBe(22.9)
        ->and($cold['output_liters'])->toBe(1000.0)
        ->and($cold['quality_class'])->toBe('EVO')
        ->and($cold['warnings'])->toBe([])
        ->and($warm['cold_extraction'])->toBeFalse()
        ->and($warm['quality_class'])->toBe('VERGINE')
        ->and($warm['warnings'])->toContain('quality_below_declared')
        ->and($grain['yield_percent'])->toBe(80.0)
        ->and($grain['output_liters'])->toBe(0.0)
        ->and($grain['cold_extraction'])->toBeFalse();
});

test('an extra virgin lot whose acidity is above 0.8 is flagged', function (): void {
    $service = new YieldCalculationService(new OilDensityConverter(0.916), 27.0, OM_THRESHOLDS);

    expect($service->evaluate(1000.0, 150.0, 25.0, new AcidityProfile(0.9, 10.0), Certification::Evo, true)['warnings'])->toContain('evo_acidity_exceeded');
});

// --- Molenda (in-kind payment) ------------------------------------------------------------------------------------------------------------

test('a cash settlement prices the service per quintal of olives and leaves the whole total to pay', function (): void {
    $result = omMolenda();

    expect($result->serviceNet)->toBe('400.00')
        ->and($result->serviceVat)->toBe('88.00')
        ->and($result->serviceTotal)->toBe('488.00')
        ->and($result->retainedKg)->toBe('0.000')
        ->and($result->purchaseTotal)->toBe('0.00')
        ->and($result->cashBalance)->toBe('488.00')
        ->and($result->cashDirection)->toBe('farmer_pays')
        ->and($result->farmerPickupKg)->toBe('800.000');
});

test('a hybrid settlement keeps a share of the oil and nets the two documents', function (): void {
    $result = omMolenda([
        'tariff' => TariffMode::PerKgOil, 'tariffValue' => 0.30, 'mode' => SettlementMode::HybridMolenda, 'molendaPercent' => 10.0, 'oilPricePerKg' => 8.5,
    ]);

    expect($result->serviceNet)->toBe('240.00')
        ->and($result->serviceTotal)->toBe('292.80')
        ->and($result->retainedKg)->toBe('80.000')
        ->and($result->retainedPercent)->toBe('10.00')
        ->and($result->retainedValueNet)->toBe('680.00')
        ->and($result->purchaseVat)->toBe('27.20')
        ->and($result->purchaseTotal)->toBe('707.20')
        ->and($result->cashBalance)->toBe('-414.40')
        ->and($result->compensation)->toBe('292.80')
        ->and($result->cashDirection)->toBe('mill_pays')
        ->and($result->farmerPickupKg)->toBe('720.000');
});

test('a fixed quantity of retained oil wins over the percentage', function (): void {
    $result = omMolenda(['mode' => SettlementMode::HybridMolenda, 'molendaPercent' => 50.0, 'fixedRetainedKg' => 25.0, 'oilPricePerKg' => 8.0]);

    expect($result->retainedKg)->toBe('25.000')->and($result->retainedValueNet)->toBe('200.00');
});

test('a full in-kind settlement keeps exactly the oil that covers the service total', function (): void {
    $result = omMolenda(['tariff' => TariffMode::FlatFee, 'tariffValue' => 100.0, 'mode' => SettlementMode::FullInKindMolenda, 'oilPricePerKg' => 10.0]);

    expect($result->serviceTotal)->toBe('122.00')
        ->and($result->retainedKg)->toBe('11.731')
        ->and($result->retainedValueNet)->toBe('117.31')
        ->and($result->purchaseVat)->toBe('4.69')
        ->and($result->purchaseTotal)->toBe('122.00')
        ->and($result->cashBalance)->toBe('0.00')
        ->and($result->cashDirection)->toBe('none');
});

test('the pure percentage tariff is the retained oil itself', function (): void {
    $result = omMolenda(['outputKg' => 1000.0, 'availableKg' => 1000.0, 'tariff' => TariffMode::PurePercentageMolenda, 'tariffValue' => 8.0, 'mode' => SettlementMode::FullInKindMolenda, 'oilPricePerKg' => 7.0]);

    expect($result->retainedKg)->toBe('80.000')
        ->and($result->serviceNet)->toBe('560.00')
        ->and($result->serviceVat)->toBe('123.20')
        ->and($result->serviceTotal)->toBe('683.20')
        ->and($result->purchaseTotal)->toBe('582.40')
        ->and($result->cashBalance)->toBe('100.80')
        ->and($result->cashDirection)->toBe('farmer_pays');
});

test('the molenda maths refuses impossible inputs', function (): void {
    expect(omErrorCode(fn () => omMolenda(['mode' => SettlementMode::HybridMolenda, 'molendaPercent' => 10.0, 'availableKg' => 50.0, 'oilPricePerKg' => 8.0])))->toBe('retained_exceeds_available')
        ->and(omErrorCode(fn () => omMolenda(['tariff' => TariffMode::PurePercentageMolenda, 'tariffValue' => 8.0])))->toBe('percentage_tariff_requires_in_kind')
        ->and(omErrorCode(fn () => omMolenda(['mode' => SettlementMode::HybridMolenda, 'molendaPercent' => 10.0, 'oilPricePerKg' => 0.0])))->toBe('oil_price_required')
        ->and(omErrorCode(fn () => omMolenda(['mode' => SettlementMode::HybridMolenda])))->toBe('molenda_share_required')
        ->and(omErrorCode(fn () => omMolenda(['mode' => SettlementMode::HybridMolenda, 'molendaPercent' => 120.0, 'oilPricePerKg' => 8.0])))->toBe('percentage_out_of_range')
        ->and(omErrorCode(fn () => omMolenda(['tariffValue' => -1.0])))->toBe('negative_value');
});

// --- SIAN rules ---------------------------------------------------------------------------------------------------------------------------

test('blending plain categories downgrades to the lowest grade', function (): void {
    $rules = new SianComplianceService;

    expect($rules->mixCategory(null, 0.0, OilCategory::Evo))->toBe(['category' => OilCategory::Evo, 'downgraded' => false])
        ->and($rules->mixCategory(OilCategory::Evo, 100.0, OilCategory::Evo))->toBe(['category' => OilCategory::Evo, 'downgraded' => false])
        ->and($rules->mixCategory(OilCategory::Evo, 100.0, OilCategory::Vergine))->toBe(['category' => OilCategory::Vergine, 'downgraded' => true])
        ->and($rules->mixCategory(OilCategory::Vergine, 100.0, OilCategory::Lampante))->toBe(['category' => OilCategory::Lampante, 'downgraded' => true])
        ->and($rules->mixCategory(OilCategory::Dop, 0.0, OilCategory::Evo))->toBe(['category' => OilCategory::Evo, 'downgraded' => false]);
});

test('protected designations and organic oil are never blended with another category', function (): void {
    $rules = new SianComplianceService;

    expect(omErrorCode(fn () => $rules->mixCategory(OilCategory::Dop, 100.0, OilCategory::Evo)))->toBe('dop_igp_mixing')
        ->and(omErrorCode(fn () => $rules->mixCategory(OilCategory::Evo, 100.0, OilCategory::Igp)))->toBe('dop_igp_mixing')
        ->and(omErrorCode(fn () => $rules->mixCategory(OilCategory::Dop, 100.0, OilCategory::Igp)))->toBe('dop_igp_mixing')
        ->and(omErrorCode(fn () => $rules->mixCategory(OilCategory::Bio, 100.0, OilCategory::Evo)))->toBe('bio_segregation')
        ->and($rules->mixCategory(OilCategory::Dop, 100.0, OilCategory::Dop)['category'])->toBe(OilCategory::Dop);
});

test('the origin of a blend is the widest one and a specific DOP origin stays alone', function (): void {
    $rules = new SianComplianceService;

    expect($rules->mixOrigin(OriginDesignation::Italia, 10.0, OriginDesignation::Ue))->toBe(OriginDesignation::Ue)
        ->and($rules->mixOrigin(OriginDesignation::Ue, 10.0, OriginDesignation::ExtraUe))->toBe(OriginDesignation::ExtraUe)
        ->and($rules->mixOrigin(OriginDesignation::Italia, 10.0, OriginDesignation::Italia))->toBe(OriginDesignation::Italia)
        ->and($rules->mixOrigin(OriginDesignation::DopSpecifica, 0.0, OriginDesignation::Italia))->toBe(OriginDesignation::Italia)
        ->and(omErrorCode(fn () => $rules->mixOrigin(OriginDesignation::DopSpecifica, 10.0, OriginDesignation::Italia)))->toBe('origin_designation_mixing');
});

test('a tank cannot overflow nor be drawn below zero', function (): void {
    $rules = new SianComplianceService;

    expect(omErrorCode(fn () => $rules->assertCapacity(1000.0, 900.0, 101.0)))->toBe('tank_capacity_exceeded')
        ->and(omErrorCode(fn () => $rules->assertCapacity(1000.0, 900.0, 100.0)))->toBeNull()
        ->and(omErrorCode(fn () => $rules->assertStock(50.0, 60.0)))->toBe('insufficient_tank_stock')
        ->and(omErrorCode(fn () => $rules->assertStock(50.0, 50.0)))->toBeNull();
});

test('the causals that name a counterparty need a valid CUAA', function (): void {
    $rules = new SianComplianceService;

    expect(omErrorCode(fn () => $rules->assertCounterparty(SianCausalCode::C02, null)))->toBe('counterparty_required')
        ->and(omErrorCode(fn () => $rules->assertCounterparty(SianCausalCode::S02, '  ')))->toBe('counterparty_required')
        ->and(omErrorCode(fn () => $rules->assertCounterparty(SianCausalCode::S01, '12345678901')))->toBe('invalid_cuaa')
        ->and(omErrorCode(fn () => $rules->assertCounterparty(SianCausalCode::C03, 'RSSMRA85T10A562S')))->toBeNull()
        ->and(omErrorCode(fn () => $rules->assertCounterparty(SianCausalCode::T01, null)))->toBeNull()
        ->and(omErrorCode(fn () => $rules->assertCounterparty(SianCausalCode::C01, null)))->toBeNull();
});

test('the SIAN deadline is six days from the operation and the alert level follows the days left', function (): void {
    $rules = new SianComplianceService(6, 2);
    $operation = Carbon::parse('2027-11-10');
    $deadline = $rules->deadline($operation);

    expect($deadline->toDateString())->toBe('2027-11-16')
        ->and($rules->daysLeft($deadline, Carbon::parse('2027-11-10')))->toBe(6)
        ->and($rules->alertLevel($deadline, Carbon::parse('2027-11-10')))->toBe('ok')
        ->and($rules->alertLevel($deadline, Carbon::parse('2027-11-14')))->toBe('due_soon')
        ->and($rules->alertLevel($deadline, Carbon::parse('2027-11-16')))->toBe('due_soon')
        ->and($rules->daysLeft($deadline, Carbon::parse('2027-11-18')))->toBe(-2)
        ->and($rules->alertLevel($deadline, Carbon::parse('2027-11-17')))->toBe('overdue');
});

test('the CUAA is a valid fiscal code or VAT number', function (): void {
    expect(SianCuaaIdentifier::isValid('RSSMRA85T10A562S'))->toBeTrue()
        ->and(SianCuaaIdentifier::isValid('01234567897'))->toBeTrue()
        ->and(SianCuaaIdentifier::isValid('IT01234567897'))->toBeTrue()
        ->and(SianCuaaIdentifier::isValid('12345678901'))->toBeFalse()
        ->and(SianCuaaIdentifier::isValid(null))->toBeFalse()
        ->and(SianCuaaIdentifier::fromString(' rssmra85t10a562s ')->value)->toBe('RSSMRA85T10A562S')
        ->and(omErrorCode(fn () => SianCuaaIdentifier::fromString('nope')))->toBe('invalid_cuaa');
});

// --- Tank composition, files and enums ----------------------------------------------------------------------------------------------------

test('a withdrawal takes every lot of the tank in proportion', function (): void {
    $composition = TankComposition::add(TankComposition::add([], 'A', 600.0), 'B', 400.0);

    $split = TankComposition::withdraw($composition, 250.0);

    expect($split['removed'])->toBe(['A' => 150.0, 'B' => 100.0])
        ->and($split['remaining'])->toBe(['A' => 450.0, 'B' => 300.0])
        ->and(TankComposition::withdraw($composition, 1000.0)['remaining'])->toBe([])
        ->and(TankComposition::withdraw([], 10.0)['removed'])->toBe([])
        ->and(TankComposition::merge(['A' => 1.0], ['A' => 2.0, 'C' => 3.0]))->toBe(['A' => 3.0, 'C' => 3.0]);
});

test('the SIAN batch is built as XML with the totals and as a CSV that neutralises formulas', function (): void {
    $rows = [
        ['progressive' => 1, 'operation_date' => '2027-11-10', 'causal' => 'C02', 'from_tank' => null, 'to_tank' => 'T1', 'counterparty_cuaa' => 'RSSMRA85T10A562S', 'quantity_kg' => '600.000', 'quantity_l' => '655.022', 'category' => 'EVO', 'origin' => 'ITALIA'],
        ['progressive' => 2, 'operation_date' => '2027-11-11', 'causal' => 'S03', 'from_tank' => 'T1', 'to_tank' => null, 'counterparty_cuaa' => null, 'quantity_kg' => '100.000', 'quantity_l' => '109.170', 'category' => 'EVO', 'origin' => 'ITALIA', 'package_lot' => '=SUM(1;1)', 'bottle_format' => '0.50L', 'bottle_count' => 200],
    ];
    $builder = new SianFileBuilder;

    $xml = simplexml_load_string($builder->xml('01234567897', $rows));
    $csv = $builder->csv($rows);

    expect((string) $xml['cuaa'])->toBe('01234567897')
        ->and((string) $xml['operazioni'])->toBe('2')
        ->and((string) $xml['totale_kg'])->toBe('700.000')
        ->and((string) $xml->Operazione[0]->causal)->toBe('C02')
        ->and((string) $xml->Operazione[1]->bottle_format)->toBe('0.50L')
        ->and($csv)->toContain('progressive;operation_date;causal')
        ->and($csv)->toContain("'=SUM(1;1)")
        ->and(substr_count($csv, "\n"))->toBe(3);
});

test('the enumerations carry the SIAN and quality semantics', function (): void {
    expect(SianCausalCode::C03->isLoad())->toBeTrue()
        ->and(SianCausalCode::S03->isUnload())->toBeTrue()
        ->and(SianCausalCode::T01->isLoad())->toBeFalse()
        ->and(SianCausalCode::T01->isUnload())->toBeFalse()
        ->and(SianStatus::Draft->isPending())->toBeTrue()
        ->and(SianStatus::AckError->isPending())->toBeTrue()
        ->and(SianStatus::AckOk->isPending())->toBeFalse()
        ->and(OilCategory::Dop->grade())->toBe(3)
        ->and(OilCategory::ofGrade(2))->toBe(OilCategory::Vergine)
        ->and(OilCategory::Bio->isSegregated())->toBeTrue()
        ->and(OilCategory::Evo->isSegregated())->toBeFalse()
        ->and(Certification::Biologico->oilCategory())->toBe(OilCategory::Bio)
        ->and(Certification::Convenzionale->oilCategory())->toBe(OilCategory::Evo)
        ->and(BottleFormat::L075->liters())->toBe(0.75)
        ->and(BottleFormat::from('3L')->liters())->toBe(3.0);
});

test('the four locales of the backend dictionary have the same keys and no empty text', function (): void {
    $reference = require lang_path('en/olive_mill.php');

    foreach (['it', 'de', 'fr'] as $locale) {
        $dictionary = require lang_path("{$locale}/olive_mill.php");

        foreach ($reference as $section => $entries) {
            expect(array_keys($dictionary[$section]))->toBe(array_keys($entries), "{$locale}.{$section}");

            foreach ($dictionary[$section] as $key => $text) {
                expect(trim($text))->not->toBe('', "{$locale}.{$section}.{$key}");
                // The placeholders of a text are the same in every language.
                preg_match_all('/:[a-z_]+/', $entries[$key], $expected);
                preg_match_all('/:[a-z_]+/', $text, $found);
                expect($found[0])->toEqualCanonicalizing($expected[0], "{$locale}.{$section}.{$key}");
            }
        }
    }
});
