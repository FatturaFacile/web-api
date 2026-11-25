<?php

declare(strict_types=1);

use Modules\CpqConfigurator\Services\ConfigurationHasher;
use Modules\CpqConfigurator\Services\PartNumberGenerator;
use Modules\CpqConfigurator\Services\PricingEngine;

require_once __DIR__.'/CpqFixtures.php';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cpqPricedDefinition(array $overrides = []): array
{
    $definition = cpqDefinition([cpqCharacteristic('length', 'range', ['min' => 1.0, 'max' => 100.0])]);

    return array_replace($definition, [
        'base_cost' => 100.0, 'base_price' => 0.0, 'base_lead_time_days' => 3, 'target_margin_percent' => 20.0, 'max_discount_percent' => 10.0,
        'bom' => [
            ['id' => 'b1', 'code' => 'cable', 'item_code' => 'CABLE', 'item_name' => ['en' => 'Cable'], 'uom' => 'M', 'condition' => null, 'quantity_formula' => 'length * 1.1', 'unit_cost' => 2.0, 'unit_cost_formula' => null, 'lead_time_days' => 4, 'auto_include_message' => null, 'active' => true],
            ['id' => 'b2', 'code' => 'kit', 'item_code' => 'KIT', 'item_name' => ['en' => 'Kit'], 'uom' => 'PCS', 'condition' => 'length > 50', 'quantity_formula' => '1', 'unit_cost' => 0.0, 'unit_cost_formula' => '30 + length', 'lead_time_days' => 9, 'auto_include_message' => ['en' => 'Added for {length} m'], 'active' => true],
            ['id' => 'b3', 'code' => 'off', 'item_code' => 'OFF', 'item_name' => ['en' => 'Off'], 'uom' => 'PCS', 'condition' => null, 'quantity_formula' => '1', 'unit_cost' => 999.0, 'unit_cost_formula' => null, 'lead_time_days' => 99, 'auto_include_message' => null, 'active' => false],
            ['id' => 'b4', 'code' => 'zero', 'item_code' => 'ZERO', 'item_name' => ['en' => 'Zero'], 'uom' => 'PCS', 'condition' => null, 'quantity_formula' => 'length - length', 'unit_cost' => 500.0, 'unit_cost_formula' => null, 'lead_time_days' => 50, 'auto_include_message' => null, 'active' => true],
        ],
        'routing' => [
            ['id' => 'r1', 'code' => 'assembly', 'operation_name' => ['en' => 'Assembly'], 'work_center' => 'WC1', 'condition' => null, 'setup_hours_formula' => '0.5', 'run_hours_formula' => 'length * 0.1', 'hourly_rate' => 50.0, 'active' => true],
            ['id' => 'r2', 'code' => 'skipped', 'operation_name' => ['en' => 'Skipped'], 'work_center' => 'WC2', 'condition' => 'length > 1000', 'setup_hours_formula' => '5', 'run_hours_formula' => '5', 'hourly_rate' => 80.0, 'active' => true],
        ],
        'pricing' => [],
    ], $overrides);
}

it('resolves the super-BOM lines from conditions and parametric quantities', function (): void {
    $result = (new PricingEngine)->price(cpqPricedDefinition(), ['length' => 20.0]);

    expect(array_column($result['bom'], 'item_code'))->toBe(['CABLE'])
        ->and($result['bom'][0]['quantity'])->toBe(22.0)
        ->and($result['bom'][0]['line_cost'])->toBe(44.0);

    $long = (new PricingEngine)->price(cpqPricedDefinition(), ['length' => 60.0]);

    expect(array_column($long['bom'], 'item_code'))->toBe(['CABLE', 'KIT'])
        ->and($long['bom'][1]['unit_cost'])->toBe(90.0)
        ->and($long['bom'][1]['auto_include_message'])->toBe('Added for 60 m');
});

it('resolves the routing operations and their cost from formulas', function (): void {
    $result = (new PricingEngine)->price(cpqPricedDefinition(), ['length' => 20.0]);

    expect($result['routing'])->toHaveCount(1)
        ->and($result['routing'][0])->toMatchArray(['work_center' => 'WC1', 'setup_hours' => 0.5, 'run_hours' => 2.0, 'hours' => 2.5, 'cost' => 125.0])
        ->and($result['totals']['routing_cost'])->toBe(125.0);
});

it('computes industrial cost, price from the target margin, selling price, margin and lead time', function (): void {
    $totals = (new PricingEngine)->price(cpqPricedDefinition(), ['length' => 20.0])['totals'];

    // cost = 100 base + 44 BOM + 125 routing
    expect($totals['industrial_cost'])->toBe(269.0)
        ->and($totals['list_price'])->toBe(336.25)
        ->and($totals['target_margin_applied'])->toBeTrue()
        ->and($totals['selling_price'])->toBe(336.25)
        ->and($totals['margin_amount'])->toBe(67.25)
        ->and($totals['margin_percent'])->toBe(20.0)
        // 3 base + 4 longest purchase + ceil(2.5 h / 8 h) = 1 day
        ->and($totals['lead_time_days'])->toBe(8)
        ->and($totals['currency'])->toBe('EUR');
});

it('applies the pricing effects in order and exposes cost_subtotal and list_subtotal to the formulas', function (): void {
    $definition = cpqPricedDefinition(['pricing' => [
        ['id' => 'p1', 'code' => 'extra-cost', 'label' => ['en' => 'Extra cost'], 'effect' => 'add_cost', 'condition' => null, 'formula' => '31', 'active' => true],
        ['id' => 'p2', 'code' => 'cost-plus', 'label' => ['en' => 'Cost plus'], 'effect' => 'set_price', 'condition' => null, 'formula' => 'cost_subtotal * 2', 'active' => true],
        ['id' => 'p3', 'code' => 'surcharge', 'label' => ['en' => 'Surcharge'], 'effect' => 'add_price', 'condition' => 'length > 10', 'formula' => '50', 'active' => true],
        ['id' => 'p4', 'code' => 'warranty', 'label' => ['en' => 'Warranty'], 'effect' => 'multiply_price', 'condition' => null, 'formula' => '1 + 0.1', 'active' => true],
        ['id' => 'p5', 'code' => 'volume', 'label' => ['en' => 'Volume'], 'effect' => 'discount_percent', 'condition' => null, 'formula' => 'if(quantity >= 5, 5, 0)', 'active' => true],
        ['id' => 'p6', 'code' => 'inactive', 'label' => ['en' => 'Inactive'], 'effect' => 'add_price', 'condition' => null, 'formula' => '1000', 'active' => false],
    ]]);

    $one = (new PricingEngine)->price($definition, ['length' => 20.0], 1.0);
    $five = (new PricingEngine)->price($definition, ['length' => 20.0], 5.0);

    // cost 269 + 31 = 300; list = (300 * 2 + 50) * 1.1 = 715
    expect($one['totals']['industrial_cost'])->toBe(300.0)
        ->and($one['totals']['list_price'])->toBe(715.0)
        ->and($one['totals']['selling_price'])->toBe(715.0)
        ->and($one['totals']['target_margin_applied'])->toBeFalse()
        ->and(array_column($one['components'], 'code'))->toBe(['extra-cost', 'cost-plus', 'surcharge', 'warranty', 'volume'])
        ->and($five['totals']['discount_percent'])->toBe(5.0)
        ->and($five['totals']['selling_price'])->toBe(679.25)
        ->and($five['totals']['total_selling_price'])->toBe(3396.25);
});

it('caps the discount at the maximum of the model, extra discount included', function (): void {
    $definition = cpqPricedDefinition(['base_price' => 1000.0]);

    $totals = (new PricingEngine)->price($definition, ['length' => 20.0], 1.0, 40.0)['totals'];

    expect($totals['discount_percent'])->toBe(10.0)
        ->and($totals['selling_price'])->toBe(900.0);
});

it('reports a formula that fails and counts it as zero instead of breaking the price', function (): void {
    $definition = cpqPricedDefinition(['pricing' => [
        ['id' => 'p1', 'code' => 'broken', 'label' => ['en' => 'Broken'], 'effect' => 'add_price', 'condition' => null, 'formula' => '1 / 0', 'active' => true],
    ]]);

    $result = (new PricingEngine)->price($definition, ['length' => 20.0]);

    expect($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]['code'])->toBe('broken')
        ->and($result['components'])->toBe([]);
});

it('gives the same hash to the same configuration however it is written and a different one when it changes', function (): void {
    $definition = cpqDefinition([
        cpqList('material', ['A', 'B'], 'A'),
        cpqCharacteristic('flow', 'range', ['min' => 1.0, 'max' => 100.0]),
        cpqCharacteristic('kit', 'boolean', ['required' => false]),
        cpqCharacteristic('hidden_one', 'boolean', ['required' => false]),
    ]);
    $hasher = new ConfigurationHasher;
    $visible = ['material' => true, 'flow' => true, 'kit' => true, 'hidden_one' => true];

    $a = $hasher->hash($hasher->canonical($definition, ['kit' => true, 'material' => 'A', 'flow' => 40.0, 'hidden_one' => false], $visible));
    $b = $hasher->hash($hasher->canonical($definition, ['flow' => 40.0000, 'hidden_one' => false, 'material' => 'A', 'kit' => true], $visible));
    $c = $hasher->hash($hasher->canonical($definition, ['kit' => true, 'material' => 'A', 'flow' => 41.0, 'hidden_one' => false], $visible));
    $d = $hasher->hash($hasher->canonical([...$definition, 'version' => 2], ['kit' => true, 'material' => 'A', 'flow' => 40.0, 'hidden_one' => false], $visible));
    $hiddenIgnored = $hasher->hash($hasher->canonical($definition, ['kit' => true, 'material' => 'A', 'flow' => 40.0, 'hidden_one' => true], [...$visible, 'hidden_one' => false]));

    expect($a)->toMatch('/^[0-9a-f]{64}$/')
        ->and($b)->toBe($a)
        ->and($c)->not->toBe($a)
        ->and($d)->not->toBe($a)
        ->and($hiddenIgnored)->toBe($hasher->hash($hasher->canonical($definition, ['kit' => true, 'material' => 'A', 'flow' => 40.0, 'hidden_one' => false], [...$visible, 'hidden_one' => false])));
});

it('normalises numbers so 40, 40.0 and 40.0000 are the same answer', function (): void {
    expect(ConfigurationHasher::number(40.0))->toBe('40')
        ->and(ConfigurationHasher::number(40.5))->toBe('40.5')
        ->and(ConfigurationHasher::number(0.1 + 0.2))->toBe('0.3')
        ->and(ConfigurationHasher::number(-0.00001))->toBe('0');
});

it('builds the smart part number from the segments of the characteristics, in position order', function (): void {
    $material = cpqList('material', ['AISI_304', 'AISI_316'], 'AISI_304');
    $material['part_number'] = ['position' => 1];
    $material['options'][0]['part_number_code'] = 'S4';
    $material['options'][1]['part_number_code'] = 's6-';
    $flow = cpqCharacteristic('flow', 'range', ['part_number' => ['position' => 3, 'prefix' => 'Q']]);
    $kit = cpqCharacteristic('kit', 'boolean', ['part_number' => ['position' => 2, 'true_code' => 'SK']]);
    $definition = [...cpqDefinition([$flow, $kit, $material]), 'part_number_prefix' => 'PSK'];
    $generator = new PartNumberGenerator;
    $visible = ['material' => true, 'flow' => true, 'kit' => true];

    expect($generator->stem($definition, ['material' => 'AISI_316', 'flow' => 40.0, 'kit' => true], $visible))->toBe('PSK-S6-SK-Q40')
        ->and($generator->stem($definition, ['material' => 'AISI_304', 'flow' => 40.5, 'kit' => false], $visible))->toBe('PSK-S4-Q40.5')
        ->and($generator->stem($definition, ['material' => 'AISI_304', 'flow' => 40.0, 'kit' => true], [...$visible, 'kit' => false]))->toBe('PSK-S4-Q40');
});
