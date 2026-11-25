<?php

declare(strict_types=1);

use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Services\RollWeightCalculator;

it('computes the net weight of a roll cage as gross minus tare with three decimals', function (): void {
    $calculator = new RollWeightCalculator;

    expect($calculator->net('118.5', '38.5'))->toBe('80.000')
        ->and($calculator->net(120.4567, 38.25))->toBe('82.207')
        ->and($calculator->net('38.5', '38.5'))->toBe('0.000');
});

it('refuses a gross weight below the tare of the cage', function (): void {
    (new RollWeightCalculator)->net('30', '38.5');
})->throws(IndustrialLaundryException::class, 'gross_below_tare');

it('refuses a negative weight', function (): void {
    (new RollWeightCalculator)->net('-1', '0');
})->throws(IndustrialLaundryException::class, 'weight_negative');

it('refuses a gross weight above the limit of the cage or the configured one', function (): void {
    $calculator = new RollWeightCalculator;

    expect(fn () => $calculator->net('500', '40', '450'))->toThrow(IndustrialLaundryException::class, 'gross_above_limit');

    config(['industrial_laundry.roll_cage.max_gross_weight_kg' => '200']);

    expect(fn () => $calculator->net('250', '40'))->toThrow(IndustrialLaundryException::class, 'gross_above_limit')
        ->and($calculator->net('199.9', '40'))->toBe('159.900');
});
