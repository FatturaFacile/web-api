<?php

declare(strict_types=1);

use Database\Seeders\Translations\BondedWarehousesFrontendTranslations;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Modules\BondedWarehouses\Domain\Enums\AllocationMethod;
use Modules\BondedWarehouses\Domain\Enums\DischargeType;
use Modules\BondedWarehouses\Domain\Enums\DiscrepancyStatus;
use Modules\BondedWarehouses\Domain\Enums\SdiDocumentType;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Domain\Support\ComplianceClassifier;
use Modules\BondedWarehouses\Domain\Support\CustomsCodes;
use Modules\BondedWarehouses\Domain\Support\DischargePlanner;
use Modules\BondedWarehouses\Domain\Support\ProportionalShare;
use Modules\BondedWarehouses\Domain\Support\VatDepotCalculator;
use Modules\BondedWarehouses\Models\BondedMrnLot;

/**
 * @param  array<string, mixed>  $overrides
 */
function bwMemoryLot(string $id, string $entryDate, array $overrides = []): BondedMrnLot
{
    $lot = new BondedMrnLot(array_merge([
        'authorization_id' => 'a0000000-0000-7000-8000-000000000001',
        'mrn_number' => '26IT'.str_pad(substr($id, -3), 14, '0', STR_PAD_LEFT),
        'mrn_item_number' => 1,
        'entry_date' => $entryDate,
        'initial_quantity' => 100, 'remaining_quantity' => 100,
        'initial_net_weight_kg' => 100, 'remaining_net_weight_kg' => 100,
        'initial_gross_weight_kg' => 110, 'remaining_gross_weight_kg' => 110,
        'customs_statistical_value' => 1000, 'suspended_duty_amount' => 100, 'suspended_vat_amount' => 242, 'cgu_engaged_amount' => 342,
    ], $overrides));
    $lot->id = $id;

    return $lot;
}

it('validates the MRN and the TARIC code formats', function (string $mrn, bool $valid): void {
    expect(CustomsCodes::isValidMrn($mrn))->toBe($valid);
})->with([
    ['26IT000123456789AB', true],
    [' 26it000123456789ab ', true],
    ['26IT00012345678', false],
    ['IT26000123456789AB', false],
    ['26IT000123456789AB1', false],
    ['26IT0001234567-9AB', false],
]);

it('normalizes the MRN and checks the TARIC code', function (): void {
    expect(CustomsCodes::normalizeMrn(' 26it 0001 2345 6789 ab'))->toBe('26IT000123456789AB')
        ->and(CustomsCodes::isValidTaric('09011100'))->toBeTrue()
        ->and(CustomsCodes::isValidTaric('0901110012'))->toBeTrue()
        ->and(CustomsCodes::isValidTaric('090111'))->toBeFalse()
        ->and(CustomsCodes::isValidTaric('0901A100'))->toBeFalse();
});

it('apportions an amount to the quantity so that the shares add up to exactly the amount', function (): void {
    $remaining = 3.0;
    $total = 0.0;

    foreach ([1.0, 1.0, 1.0] as $quantity) {
        $total += ProportionalShare::of(100.0, 3.0, $remaining, $quantity);
        $remaining -= $quantity;
    }

    expect(round($total, 2))->toBe(100.0)
        ->and(ProportionalShare::of(100.0, 3.0, 3.0, 1.0))->toBe(33.33)
        ->and(ProportionalShare::of(100.0, 3.0, 2.0, 1.0))->toBe(33.34)
        ->and(ProportionalShare::of(342.0, 100.0, 100.0, 50.0))->toBe(171.0)
        ->and(ProportionalShare::of(100.0, 0.0, 0.0, 1.0))->toBe(0.0);
});

it('plans a FIFO discharge over the oldest lots first', function (): void {
    $newer = bwMemoryLot('b0000000-0000-7000-8000-000000000002', '2027-05-01');
    $older = bwMemoryLot('b0000000-0000-7000-8000-000000000001', '2027-04-01');

    $plan = (new DischargePlanner)->plan(AllocationMethod::FIFO, DischargeType::IM4FreeCirculation, new Collection([$newer, $older]), 130.0);

    expect($plan)->toHaveCount(2)
        ->and($plan[0]['lot']->id)->toBe($older->id)
        ->and($plan[0]['quantity'])->toBe(100.0)
        ->and($plan[0]['duty_amount'])->toBe(100.0)
        ->and($plan[0]['cgu_release_amount'])->toBe(342.0)
        ->and($plan[1]['lot']->id)->toBe($newer->id)
        ->and($plan[1]['quantity'])->toBe(30.0)
        ->and($plan[1]['released_customs_value'])->toBe(300.0)
        ->and($plan[1]['paid_vat_amount'])->toBe(72.6)
        ->and($plan[1]['net_weight_kg'])->toBe(30.0);
});

it('plans a Specific MRN discharge and refuses more than the lot holds', function (): void {
    $lot = bwMemoryLot('b0000000-0000-7000-8000-000000000001', '2027-04-01');
    $planner = new DischargePlanner;

    $plan = $planner->plan(AllocationMethod::SpecificMRN, DischargeType::EX3ReExport, new Collection([$lot]), 0.0, [['mrn_lot_id' => $lot->id, 'quantity' => 25.0]]);

    expect($plan[0]['extinguished_duty_amount'])->toBe(25.0)
        ->and($plan[0]['extinguished_vat_amount'])->toBe(60.5)
        ->and($plan[0]['paid_duty_amount'])->toBe(0.0)
        ->and($plan[0]['cgu_release_amount'])->toBe(85.5);

    expect(fn () => $planner->plan(AllocationMethod::SpecificMRN, DischargeType::EX3ReExport, new Collection([$lot]), 0.0, [['mrn_lot_id' => $lot->id, 'quantity' => 101.0]]))
        ->toThrow(BondedWarehouseException::class, 'insufficient_remaining_quantity');
});

it('refuses a FIFO discharge beyond the total balance and a lot that is not available', function (): void {
    $lot = bwMemoryLot('b0000000-0000-7000-8000-000000000001', '2027-04-01');
    $planner = new DischargePlanner;

    expect(fn () => $planner->plan(AllocationMethod::FIFO, DischargeType::IM4FreeCirculation, new Collection([$lot]), 100.5))->toThrow(BondedWarehouseException::class, 'insufficient_remaining_quantity')
        ->and(fn () => $planner->plan(AllocationMethod::SpecificMRN, DischargeType::IM4FreeCirculation, new Collection([$lot]), 0.0, [['mrn_lot_id' => 'unknown', 'quantity' => 1.0]]))->toThrow(BondedWarehouseException::class, 'lot_not_found');
});

it('releases no guarantee and creates no debt on a usual handling', function (): void {
    $lot = bwMemoryLot('b0000000-0000-7000-8000-000000000001', '2027-04-01');

    $plan = (new DischargePlanner)->plan(AllocationMethod::FIFO, DischargeType::UsualHandlingAnnex7103, new Collection([$lot]), 40.0);

    expect($plan[0]['cgu_release_amount'])->toBe(0.0)
        ->and($plan[0]['paid_duty_amount'])->toBe(0.0)
        ->and($plan[0]['extinguished_duty_amount'])->toBe(0.0)
        ->and($plan[0]['extinguished_vat_amount'])->toBe(0.0);
});

it('takes the taxable base of an extraction as the goods plus the services', function (): void {
    expect(VatDepotCalculator::extraction(1000.0, 200.0, 22.0))->toBe(['base' => 1200.0, 'vat_amount' => 264.0, 'total' => 1464.0])
        ->and(VatDepotCalculator::extraction(100.0, 0.0, 0.0)['vat_amount'])->toBe(0.0)
        ->and(VatDepotCalculator::extraction(33.33, 0.01, 22.0)['vat_amount'])->toBe(7.33);
});

it('picks TD22 or TD23 from the movement and requires the F24 only for TD23', function (): void {
    expect(VatDepotCalculator::documentTypeOf(VatDepotMovementType::ExtractionTD22))->toBe(SdiDocumentType::TD22)
        ->and(VatDepotCalculator::documentTypeOf(VatDepotMovementType::ExtractionLetterOfIntent))->toBe(SdiDocumentType::TD22)
        ->and(VatDepotCalculator::documentTypeOf(VatDepotMovementType::ExtractionTD23))->toBe(SdiDocumentType::TD23)
        ->and(VatDepotCalculator::documentTypeOf(VatDepotMovementType::InboundIntraEU))->toBe(SdiDocumentType::None);

    expect(fn () => VatDepotCalculator::assertPaymentMode(VatDepotMovementType::ExtractionTD23, null, null))->toThrow(BondedWarehouseException::class, 'f24_required')
        ->and(fn () => VatDepotCalculator::assertPaymentMode(VatDepotMovementType::ExtractionTD22, 'F24-1', '2027-07-01'))->toThrow(BondedWarehouseException::class, 'f24_not_allowed');

    VatDepotCalculator::assertPaymentMode(VatDepotMovementType::ExtractionTD23, 'F24-1', '2027-07-01');
    VatDepotCalculator::assertPaymentMode(VatDepotMovementType::ExtractionTD22, null, null);
});

it('classifies the variance of the reconciliation and the CGU thresholds', function (): void {
    expect(ComplianceClassifier::variance(100.0, 100.0))->toBe(DiscrepancyStatus::Matched)
        ->and(ComplianceClassifier::variance(99.5, 100.0))->toBe(DiscrepancyStatus::MinorVariance)
        ->and(ComplianceClassifier::variance(90.0, 100.0))->toBe(DiscrepancyStatus::CriticalAlertADMUDSC)
        ->and(ComplianceClassifier::variance(5.0, 0.0))->toBe(DiscrepancyStatus::CriticalAlertADMUDSC)
        ->and(ComplianceClassifier::cguThreshold(79.99))->toBe(DiscrepancyStatus::Matched)
        ->and(ComplianceClassifier::cguThreshold(80.0))->toBe(DiscrepancyStatus::MinorVariance)
        ->and(ComplianceClassifier::cguThreshold(94.99))->toBe(DiscrepancyStatus::MinorVariance)
        ->and(ComplianceClassifier::cguThreshold(95.0))->toBe(DiscrepancyStatus::CriticalAlertADMUDSC);
});

it('serves the same translation keys in the four languages and the four backend dictionaries', function (): void {
    $keys = array_keys(BondedWarehousesFrontendTranslations::all('it'));

    foreach (['en', 'de', 'fr'] as $locale) {
        expect(array_keys(BondedWarehousesFrontendTranslations::all($locale)))->toBe($keys);
    }

    foreach (BondedWarehousesFrontendTranslations::definitions() as $key => $tuple) {
        expect(array_filter($tuple, fn (string $text): bool => $text === ''), $key)->toBeEmpty();
    }

    $backend = [];

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dictionary = require base_path("lang/{$locale}/bonded_warehouses.php");
        $backend[$locale] = array_keys(Arr::dot($dictionary));
    }

    expect($backend['en'])->toBe($backend['it'])->and($backend['de'])->toBe($backend['it'])->and($backend['fr'])->toBe($backend['it']);
});
