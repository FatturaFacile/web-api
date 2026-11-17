<?php

declare(strict_types=1);

use Modules\Inventory\Support\Decimal;

test('rounds half-up away from zero at the requested scale', function (string $value, int $scale, string $expected): void {
    expect(Decimal::round($value, $scale))->toBe($expected);
})->with([
    ['2.345', 2, '2.35'],
    ['2.3449999', 2, '2.34'],
    ['-2.345', 2, '-2.35'],
    ['5.66666', 4, '5.6667'],
    ['0.00004', 4, '0.0000'],
    ['-0.004', 2, '0.00'],
    ['10', 2, '10.00'],
    ['12.5', 0, '13'],
]);

test('normalises floats and scientific notation', function (): void {
    expect(Decimal::of(0.1 + 0.2))->toStartWith('0.3000000000')
        ->and(Decimal::money(1.0E-5))->toBe('0.00')
        ->and(Decimal::of(15))->toBe('15');
});

test('largest remainder allocation sums exactly to the total', function (array $weights, string $total, array $expected): void {
    $parts = Decimal::allocate($total, $weights);

    expect($parts)->toBe($expected)
        ->and(array_reduce($parts, static fn (string $carry, string $part): string => bcadd($carry, $part, 2), '0'))->toBe(Decimal::money($total));
})->with([
    'by value 1000/500' => [['a' => '1000', 'b' => '500'], '300.00', ['a' => '200.00', 'b' => '100.00']],
    'three equal shares' => [['a' => '1', 'b' => '1', 'c' => '1'], '100.00', ['a' => '33.34', 'b' => '33.33', 'c' => '33.33']],
    'uneven weights' => [['a' => '3', 'b' => '3', 'c' => '1'], '0.10', ['a' => '0.04', 'b' => '0.04', 'c' => '0.02']],
    'zero weight gets nothing' => [['a' => '0', 'b' => '7'], '12.34', ['a' => '0.00', 'b' => '12.34']],
    'negative total (reversal)' => [['a' => '1', 'b' => '2'], '-10.00', ['a' => '-3.33', 'b' => '-6.67']],
]);

test('allocation requires a positive weight', function (): void {
    Decimal::allocate('10.00', ['a' => '0', 'b' => '0']);
})->throws(InvalidArgumentException::class);

test('division by zero is rejected', function (): void {
    Decimal::div('1', '0');
})->throws(InvalidArgumentException::class);
