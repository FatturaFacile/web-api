<?php

declare(strict_types=1);

use App\Services\Sales\Cpq\MarginCalculator;

beforeEach(function (): void {
    $this->calculator = new MarginCalculator;
});

test('computes list, net, cost and margin of a line', function (): void {
    $line = $this->calculator->line(['quantity' => '2', 'unit_price' => '1000', 'discount_percentage' => '10', 'unit_cost' => '600', 'labor_cost' => '50']);

    expect($line)->toMatchArray([
        'list_total' => '2000.00',
        'net_total' => '1800.00',
        'cost_total' => '1300.00',
        'margin_amount' => '500.00',
        'margin_percent' => '27.78',
    ]);
});

test('applies the global discount, surcharge and setup cost at quote level', function (): void {
    $result = $this->calculator->calculate([
        ['quantity' => '1', 'unit_price' => '1000', 'unit_cost' => '500'],
        ['quantity' => '2', 'unit_price' => '250', 'discount_percentage' => '20', 'unit_cost' => '100'],
    ], ['global_discount_percent' => '10', 'surcharge_amount' => '50', 'setup_cost' => '100']);

    expect($result['totals'])->toMatchArray([
        'list_total' => '1500.00',
        'line_discount_total' => '100.00',
        'global_discount_amount' => '140.00',
        'net_total' => '1310.00',
        'cost_total' => '800.00',
        'margin_amount' => '510.00',
        'margin_percent' => '38.93',
    ]);
});

test('a zero price has no margin percentage and no division by zero', function (): void {
    $result = $this->calculator->calculate([['quantity' => '3', 'unit_price' => '0', 'unit_cost' => '10']]);

    expect($result['totals']['net_total'])->toBe('0.00')
        ->and($result['totals']['margin_amount'])->toBe('-30.00')
        ->and($result['totals']['margin_percent'])->toBeNull()
        ->and($result['lines'][0]['margin_percent'])->toBeNull();
});

test('a negative margin is reported as it is', function (): void {
    $line = $this->calculator->line(['quantity' => '1', 'unit_price' => '80', 'unit_cost' => '100']);

    expect($line['margin_amount'])->toBe('-20.00')->and($line['margin_percent'])->toBe('-25.00');
});

test('converts a cost kept in another currency with the exchange rate', function (): void {
    $line = $this->calculator->line(['quantity' => '1', 'unit_price' => '1000', 'unit_cost' => '500', 'cost_exchange_rate' => '0.95']);

    expect($line['cost_total'])->toBe('475.00')->and($line['margin_percent'])->toBe('52.50');
});

test('clamps discounts to 0..100 and ignores a zero exchange rate', function (): void {
    $over = $this->calculator->line(['quantity' => '1', 'unit_price' => '100', 'discount_percentage' => '150', 'cost_exchange_rate' => '0']);
    $negative = $this->calculator->line(['quantity' => '1', 'unit_price' => '100', 'discount_percentage' => '-5']);

    expect($over['net_total'])->toBe('0.00')->and($negative['net_total'])->toBe('100.00');
});

test('an empty quote totals to zero', function (): void {
    expect($this->calculator->calculate([])['totals'])->toMatchArray(['net_total' => '0.00', 'cost_total' => '0.00', 'margin_percent' => null]);
});
