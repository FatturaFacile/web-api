<?php

declare(strict_types=1);

use Modules\DairyCheeseManufacturing\Domain\Lab\QualityFlags;
use Modules\DairyCheeseManufacturing\Domain\Pricing\PricingEngine;
use Modules\DairyCheeseManufacturing\Domain\Pricing\PricingInput;

/**
 * @param  list<array<string, mixed>>  $tiers
 * @return array<string, mixed>
 */
function dcGrid(array $tiers = [], string $supplement = '0', string $premium = '0'): array
{
    return ['base_price_per_kg' => '0.70', 'cheese_milk_supplement_per_kg' => $supplement, 'dop_premium_per_kg' => $premium, 'tiers' => $tiers];
}

function dcInput(float $kg = 3090.0, float $cheeseKg = 0.0, ?float $fat = 4.2, ?float $scc = 150000.0, bool $inhibitor = false): PricingInput
{
    return new PricingInput($kg, $cheeseKg, $fat, 3.4, null, null, 20000.0, $scc, -0.52, $inhibitor);
}

it('prices the base amount and adds the vat', function (): void {
    $result = (new PricingEngine)->price(dcGrid(), dcInput(), 2.6);

    expect($result->base)->toBe(2163.0)
        ->and($result->gross)->toBe(2163.0)
        ->and($result->vat)->toBe(56.24)
        ->and($result->net)->toBe(2219.24)
        ->and($result->lines)->toHaveCount(1);
});

it('applies a bonus tier and a penalty tier by the measured value', function (): void {
    $tiers = [
        ['component' => 'fat', 'from_value' => 4.0, 'to_value' => null, 'adjustment_per_kg' => '0.01'],
        ['component' => 'scc', 'from_value' => 400000, 'to_value' => null, 'adjustment_per_kg' => '-0.03'],
    ];

    $good = (new PricingEngine)->price(dcGrid($tiers), dcInput(), 0.0);
    expect($good->bonus)->toBe(30.9)->and($good->penalty)->toBe(0.0)->and($good->gross)->toBe(2193.9);

    $bad = (new PricingEngine)->price(dcGrid($tiers), dcInput(fat: 3.8, scc: 450000.0), 0.0);
    expect($bad->bonus)->toBe(0.0)->and($bad->penalty)->toBe(92.7)->and($bad->gross)->toBe(2070.3);
});

it('uses a half open range: the upper bound belongs to the next tier', function (): void {
    $tiers = [['component' => 'fat', 'from_value' => null, 'to_value' => 4.0, 'adjustment_per_kg' => '-0.02']];

    expect((new PricingEngine)->price(dcGrid($tiers), dcInput(fat: 4.0), 0.0)->penalty)->toBe(0.0)
        ->and((new PricingEngine)->price(dcGrid($tiers), dcInput(fat: 3.99), 0.0)->penalty)->toBe(61.8);
});

it('applies only the first matching tier of a component and ignores components without a measurement', function (): void {
    $tiers = [
        ['component' => 'fat', 'from_value' => 4.0, 'to_value' => null, 'adjustment_per_kg' => '0.01'],
        ['component' => 'fat', 'from_value' => 3.0, 'to_value' => null, 'adjustment_per_kg' => '0.05'],
        ['component' => 'casein', 'from_value' => 0, 'to_value' => null, 'adjustment_per_kg' => '0.10'],
    ];

    $result = (new PricingEngine)->price(dcGrid($tiers), dcInput(), 0.0);

    expect($result->bonus)->toBe(30.9)->and($result->lines)->toHaveCount(2);
});

it('treats a positive inhibitor result as a heavy penalty tier', function (): void {
    $tiers = [['component' => 'inhibitor', 'from_value' => 1, 'to_value' => null, 'adjustment_per_kg' => '-0.50']];

    expect((new PricingEngine)->price(dcGrid($tiers), dcInput(inhibitor: true), 0.0)->penalty)->toBe(1545.0)
        ->and((new PricingEngine)->price(dcGrid($tiers), dcInput(), 0.0)->penalty)->toBe(0.0);
});

it('pays the cheese-milk supplement and the DOP premium only on the milk processed into cheese', function (): void {
    $result = (new PricingEngine)->price(dcGrid([], '0.15', '0.05'), dcInput(cheeseKg: 1030.0), 0.0);

    expect($result->supplement)->toBe(206.0)->and($result->gross)->toBe(2369.0)->and($result->lines)->toHaveCount(3);

    $none = (new PricingEngine)->price(dcGrid([], '0.15', '0.05'), dcInput(cheeseKg: 0.0), 0.0);
    expect($none->supplement)->toBe(0.0);
});

describe('quality flags', function (): void {
    $limits = ['scc_cells_ml' => 400000, 'tbc_cfu_ml' => 100000, 'freezing_point_c' => -0.505];

    it('flags nothing for good milk', function () use ($limits): void {
        expect(QualityFlags::evaluate('negative', 150000, 20000, -0.52, $limits))->toBe([]);
    });

    it('flags inhibitors, somatic cells, bacteria and added water', function () use ($limits): void {
        expect(QualityFlags::evaluate('positive', 450000, 150000, -0.45, $limits))->toBe(['inhibitor_positive', 'scc_high', 'tbc_high', 'freezing_point_anomaly'])
            ->and(QualityFlags::evaluate('not_tested', null, null, null, $limits))->toBe([]);
    });
});
