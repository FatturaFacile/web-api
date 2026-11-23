<?php

declare(strict_types=1);

use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\PurchasePricingService;

it('leaves 68.4 percent of the price after a 20 + 10 + 5 cascade', function (): void {
    $pricing = new PurchasePricingService;
    $steps = $pricing->normalizeCascade([20, '10', 5, 0]);

    expect($steps)->toBe(['20.0000', '10.0000', '5.0000'])
        ->and($pricing->cascadeFactor($steps))->toBe('0.6840000000')
        ->and($pricing->effectivePercent($steps))->toBe('31.6000');
});

it('rounds the line once from the gross price, so the cascade never drifts', function (): void {
    $pricing = new PurchasePricingService;
    $steps = $pricing->normalizeCascade([20, 10, 5]);

    // 1000 pieces at 3.3333 gross: 3333.30 x 0.684 = 2279.9772
    expect($pricing->lineNet('1000.0000', '3.3333', $steps))->toBe('2279.98')
        ->and($pricing->netUnitPrice('3.3333', $steps))->toBe('2.2800')
        ->and($pricing->lineNet('10.0000', '100.0000', []))->toBe('1000.00');
});

it('rejects a discount step outside zero to one hundred percent', function (mixed $step): void {
    (new PurchasePricingService)->normalizeCascade([10, $step]);
})->with([[-1], [101], ['abc']])->throws(ProcurementRuleViolation::class);

it('converts order units to base stock units and back', function (): void {
    $pricing = new PurchasePricingService;

    expect($pricing->toBase('12.0000', '24.500000'))->toBe('294.0000')
        ->and($pricing->fromBase('294.0000', '24.500000'))->toBe('12.0000')
        ->and($pricing->pricePerBaseUnit('49.0000', '24.500000'))->toBe('2.000000')
        ->and($pricing->conversionFactor(null))->toBe('1.000000');
});

it('refuses a conversion factor that is not positive', function (): void {
    (new PurchasePricingService)->conversionFactor('0');
})->throws(ProcurementRuleViolation::class);

it('converts a foreign currency amount to the company currency', function (): void {
    $pricing = new PurchasePricingService;

    expect($pricing->toBaseCurrency('1000.00', '0.945000'))->toBe('945.00')
        ->and($pricing->toBaseCurrency('19.99', '1.087500'))->toBe('21.74');
});
