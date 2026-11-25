<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\LiquidityPlanning\Support\Flow;
use Modules\LiquidityPlanning\Support\Levers;
use Modules\LiquidityPlanning\Support\ProjectionBuilder;
use Modules\LiquidityPlanning\Support\ScenarioApplier;
use Modules\LiquidityPlanning\Support\ScenarioPresets;

function lpStart(): CarbonImmutable
{
    return CarbonImmutable::parse('2030-01-01');
}

function lpFlow(string $direction, string $category, string $amount, int $day, string $tier = Flow::Certain): Flow
{
    return new Flow(lpStart()->addDays($day), $direction, $tier, $category, $category, $amount);
}

it('clamps the levers into their ranges and keeps the neutral levers neutral', function (): void {
    $levers = Levers::fromArray(['collection_delay_days' => 999, 'inflow_change_percent' => '-500', 'raw_material_increase_percent' => 'abc', 'additional_financing_amount' => '-5']);

    expect($levers->collectionDelayDays)->toBe(180)
        ->and($levers->inflowChangePercent)->toBe('-100.00')
        ->and($levers->rawMaterialIncreasePercent)->toBe('0.00')
        ->and($levers->additionalFinancingAmount)->toBe('0.00')
        ->and(Levers::fromArray([])->isNeutral())->toBeTrue()
        ->and(Levers::fromArray(['capex_acceleration_days' => 30])->isNeutral())->toBeFalse();
});

it('builds the daily and weekly projection with the closing balance and the tiers', function (): void {
    $flows = [
        lpFlow(Flow::Inflow, 'receivable', '1000.00', 2),
        lpFlow(Flow::Outflow, 'payable', '400.00', 2),
        lpFlow(Flow::Outflow, 'payroll', '2000.00', 10, Flow::Estimated),
    ];

    $projection = (new ProjectionBuilder)->build('500.00', $flows, lpStart());

    expect($projection['daily'])->toHaveCount(181)
        ->and($projection['daily'][2]['closing'])->toBe('1100.00')
        ->and($projection['daily'][2]['tiers']['certain']['inflows'])->toBe('1000.00')
        ->and($projection['daily'][10]['tiers']['estimated']['outflows'])->toBe('2000.00')
        ->and($projection['daily'][10]['closing'])->toBe('-900.00')
        ->and($projection['negative_balance'])->toBe(['first_date' => '2030-01-11', 'min_balance' => '-900.00', 'min_date' => '2030-01-11'])
        ->and($projection['weekly'][0]['inflows'])->toBe('1000.00')
        ->and($projection['weekly'][1]['closing'])->toBe('-900.00');
});

it('puts overdue flows on the first day and drops the ones beyond the horizon', function (): void {
    $flows = [lpFlow(Flow::Inflow, 'receivable', '300.00', -20), lpFlow(Flow::Inflow, 'receivable', '999.00', 400)];

    $projection = (new ProjectionBuilder)->build('0.00', $flows, lpStart());

    expect($projection['daily'][0]['inflows'])->toBe('300.00')
        ->and($projection['daily'][180]['closing'])->toBe('300.00')
        ->and($projection['negative_balance'])->toBeNull();
});

it('summarises the 30, 60, 90 and 180 day horizons', function (): void {
    $builder = new ProjectionBuilder;
    $flows = [lpFlow(Flow::Inflow, 'receivable', '100.00', 20), lpFlow(Flow::Outflow, 'payable', '250.00', 45), lpFlow(Flow::Inflow, 'receivable', '70.00', 100)];
    $horizons = $builder->horizons('1000.00', $builder->build('1000.00', $flows, lpStart())['daily']);

    expect(array_column($horizons, 'days'))->toBe([30, 60, 90, 180])
        ->and($horizons[0]['closing_balance'])->toBe('1100.00')
        ->and($horizons[1]['closing_balance'])->toBe('850.00')
        ->and($horizons[1]['min_balance'])->toBe('850.00')
        ->and($horizons[3]['closing_balance'])->toBe('920.00')
        ->and($horizons[3]['inflows'])->toBe('170.00');
});

it('delays a share of the customer collections and leaves the baseline flows untouched', function (): void {
    $flows = [lpFlow(Flow::Inflow, 'receivable', '1000.00', 5)];
    $result = (new ScenarioApplier)->apply($flows, new Levers(collectionDelayDays: 30, collectionDelaySharePercent: 20), lpStart());

    expect($result)->toHaveCount(2)
        ->and($result[0]->amount)->toBe('200.00')
        ->and($result[0]->date->toDateString())->toBe('2030-02-05')
        ->and($result[1]->amount)->toBe('800.00')
        ->and($result[1]->date->toDateString())->toBe('2030-01-06')
        ->and($flows[0]->amount)->toBe('1000.00');
});

it('raises the supplier costs, moves the capex forward and adds a financing drawdown', function (): void {
    $flows = [
        lpFlow(Flow::Outflow, 'payable', '1000.00', 10),
        lpFlow(Flow::Outflow, 'capex', '5000.00', 60, Flow::Estimated),
        lpFlow(Flow::Outflow, 'debt_principal', '700.00', 20),
    ];
    $levers = new Levers(rawMaterialIncreasePercent: '15.00', capexAccelerationDays: 90, additionalFinancingAmount: '10000.00', additionalFinancingDate: '2030-01-15');

    $result = (new ScenarioApplier)->apply($flows, $levers, lpStart());
    $byCategory = [];

    foreach ($result as $flow) {
        $byCategory[$flow->category] = $flow;
    }

    expect($byCategory['payable']->amount)->toBe('1150.00')
        ->and($byCategory['capex']->date->toDateString())->toBe('2030-01-01')
        ->and($byCategory['debt_principal']->amount)->toBe('700.00')
        ->and($byCategory['other']->amount)->toBe('10000.00')
        ->and($byCategory['other']->date->toDateString())->toBe('2030-01-15');
});

it('offers the three presets of the simulator', function (): void {
    $presets = ScenarioPresets::all();

    expect(array_column($presets, 'code'))->toBe(['collection_delay_20', 'raw_material_spike', 'capex_acceleration'])
        ->and($presets[0]['levers']['collection_delay_share_percent'])->toBe(20)
        ->and($presets[0]['name_key'])->toBe('lpApi.preset.collection_delay_20.name');
});
