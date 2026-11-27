<?php

declare(strict_types=1);

use Modules\DownPayments\Services\MultiVatProportionalAllocator;

/**
 * The Largest Remainder split of an amount over the VAT rates of a source: exact to the cent, whatever the amount.
 */
beforeEach(function (): void {
    $this->allocator = new MultiVatProportionalAllocator;
    $this->bases = ['22.00' => '10000.00', '10.00' => '3333.33', '0.00' => '1666.67'];
});

it('splits a net amount so the nets add up to the cent with no rounding drift', function (string $net): void {
    $slices = $this->allocator->allocateNet($net, $this->bases);

    expect($this->allocator->sum($slices, 'net'))->toBe($net)
        ->and($this->allocator->sum($slices, 'gross'))->toBe(bcadd($this->allocator->sum($slices, 'net'), $this->allocator->sum($slices, 'vat'), 2));
})->with(['3333.33', '1000.01', '0.07', '14999.99', '15000.00', '1.00']);

it('gives the vat of each slice at its own rate', function (): void {
    $slices = collect($this->allocator->allocateNet('1500.00', $this->bases))->keyBy('vat_rate');

    foreach ($slices as $rate => $slice) {
        expect(abs((float) $slice['vat'] - round((float) $slice['net'] * (float) $rate / 100, 2)))->toBeLessThan(0.0001);
    }
});

it('hands the leftover cents to the largest remainders and splits an exact multiple proportionally', function (): void {
    $slices = collect($this->allocator->allocateNet('1500.00', ['22.00' => '1000.00', '10.00' => '500.00']))->keyBy('vat_rate');

    expect($slices['22.00']['net'])->toBe('1000.00')
        ->and($slices['10.00']['net'])->toBe('500.00');

    $thirds = collect($this->allocator->allocateNet('100.00', ['22.00' => '1.00', '10.00' => '1.00', '4.00' => '1.00']));

    expect($this->allocator->sum($thirds->all(), 'net'))->toBe('100.00')
        ->and($thirds->pluck('net')->sort()->values()->all())->toBe(['33.33', '33.33', '33.34']);
});

it('takes the whole residual when the amount equals the base', function (): void {
    $slices = collect($this->allocator->allocateNet('15000.00', $this->bases))->keyBy('vat_rate');

    expect($slices['22.00']['net'])->toBe('10000.00')
        ->and($slices['10.00']['net'])->toBe('3333.33')
        ->and($slices['0.00']['net'])->toBe('1666.67');
});

it('splits a gross amount over the rates and reports the drift', function (string $gross): void {
    $slices = $this->allocator->allocateGross($gross, $this->bases);

    expect($this->allocator->drift($gross, $slices))->toBe('0.00')
        ->and($this->allocator->sum($slices, 'gross'))->toBe($gross);
})->with(['5000.00', '1220.00', '100.01']);

it('reports the drift when a single rate cannot reach the gross', function (): void {
    $slices = $this->allocator->allocateGross('100.01', ['22.00' => '100.00']);

    expect($this->allocator->drift('100.01', $slices))->not->toBe('0.00');
    expect(abs((float) $this->allocator->drift('100.01', $slices)))->toBeLessThanOrEqual(0.01);
});

it('refuses a source without a positive base', function (): void {
    expect(fn () => $this->allocator->allocateNet('10.00', ['22.00' => '0.00']))->toThrow(InvalidArgumentException::class);
});

it('never allocates more than the base of a rate when the amount fits the source', function (): void {
    foreach (['0.01', '0.02', '7777.77', '14999.98'] as $net) {
        foreach ($this->allocator->allocateNet($net, $this->bases) as $slice) {
            expect(bccomp($slice['net'], $this->bases[$slice['vat_rate']], 2))->toBeLessThanOrEqual(0);
        }
    }
});
