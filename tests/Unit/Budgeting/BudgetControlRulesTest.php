<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Budgeting\Domain\Availability;
use Modules\Budgeting\Domain\SpendDecision;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetOverride;
use Modules\Budgeting\Models\BudgetSpendRule;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\GridService;
use Modules\Budgeting\Services\VarianceService;
use Modules\Budgeting\Services\VolumePriceVarianceService;

test('available is the budget less actual and commitments, utilisation is their share of the budget', function (): void {
    $availability = new Availability('v1', 2028, true, 'EUR', '12000.00', '2000.00', '2100.00');

    expect($availability->available())->toBe('7900.00')->and($availability->utilizationPercent())->toBe('34.17')
        ->and($availability->toArray())->toMatchArray(['available' => '7900.00', 'has_budget' => true, 'currency' => 'EUR']);
});

test('over-committed budgets give a negative availability and no budget gives no utilisation', function (): void {
    expect((new Availability('v1', 2028, true, 'EUR', '1000.00', '900.00', '300.00'))->available())->toBe('-200.00')
        ->and((new Availability(null, 2028, false, 'EUR', '0.00', '0.00', '250.00'))->utilizationPercent())->toBeNull()
        ->and((new Availability(null, 2028, false, 'EUR', '0.00', '0.00', '250.00'))->available())->toBe('-250.00');
});

test('the volume and the price effect add up to the variance exactly', function (): void {
    $effects = VolumePriceVarianceService::effects('1000.00', '1380.00', '10.000', '12.000');

    expect($effects['budget_price'])->toBe('100.0000')->and($effects['actual_price'])->toBe('115.0000')
        ->and($effects['volume'])->toBe('200.00')->and($effects['price'])->toBe('180.00')
        ->and(bcadd($effects['volume'], $effects['price'], 2))->toBe('380.00');
});

test('a favourable volume with an adverse price is split with opposite signs', function (): void {
    $effects = VolumePriceVarianceService::effects('1000.00', '950.00', '10.000', '8.000');

    // 8 units at 118.75 against 10 at 100: volume -200, price +150, variance -50.
    expect($effects['volume'])->toBe('-200.00')->and($effects['price'])->toBe('150.00')->and(bcadd($effects['volume'], $effects['price'], 2))->toBe('-50.00')->and($effects['actual_price'])->toBe('118.7500');
});

test('the effects stay unknown without both quantities or with a budget quantity of zero', function (): void {
    $none = ['volume' => null, 'price' => null, 'budget_price' => null, 'actual_price' => null];

    expect(VolumePriceVarianceService::effects('100.00', '120.00', null, '5.000'))->toBe($none)
        ->and(VolumePriceVarianceService::effects('100.00', '120.00', '5.000', null))->toBe($none)
        ->and(VolumePriceVarianceService::effects('100.00', '120.00', '0.000', '5.000'))->toBe($none);
});

test('no actual quantity gives no actual price but the volume effect is still known', function (): void {
    $effects = VolumePriceVarianceService::effects('1000.00', '0.00', '10.000', '0.000');

    expect($effects['volume'])->toBe('-1000.00')->and($effects['price'])->toBe('0.00')->and($effects['actual_price'])->toBeNull();
});

test('the traffic light is green inside the amber threshold, amber up to the red one and red beyond', function (): void {
    $light = static fn (string $variance, string $budget, bool $favorable = false): string => VarianceService::trafficLight($variance, $budget, $favorable, '5', '10');

    expect($light('-300.00', '1000.00', true))->toBe('green')
        ->and($light('0.00', '1000.00'))->toBe('green')
        ->and($light('40.00', '1000.00'))->toBe('green')
        ->and($light('50.00', '1000.00'))->toBe('green')
        ->and($light('50.01', '1000.00'))->toBe('amber')
        ->and($light('100.00', '1000.00'))->toBe('amber')
        ->and($light('100.01', '1000.00'))->toBe('red')
        ->and($light('1.00', '0.00'))->toBe('red')
        ->and($light('0.00', '0.00'))->toBe('green');
});

test('a negative adverse variance of a revenue is judged by its size', function (): void {
    // Revenue under plan: variance -15%, not favourable.
    expect(VarianceService::trafficLight('-150.00', '1000.00', false, '5', '10'))->toBe('red')
        ->and(VarianceService::trafficLight('-70.00', '1000.00', false, '5', '10'))->toBe('amber');
});

test('a quarter is split in three equal parts and the cents go to the last one', function (): void {
    $grid = (new ReflectionClass(GridService::class))->newInstanceWithoutConstructor();

    expect($grid->split('1000.00', 3, 2))->toBe(['333.33', '333.33', '333.34'])
        ->and($grid->split('100.00', 1, 2))->toBe(['100.00'])
        ->and($grid->split('0.01', 3, 2))->toBe(['0.00', '0.00', '0.01'])
        ->and($grid->split('10.000', 3, 3))->toBe(['3.333', '3.333', '3.334'])
        ->and(array_sum(array_map('floatval', $grid->split('999.99', 3, 2))))->toEqualWithDelta(999.99, 0.0001);
});

test('only an open commitment counts and never below zero', function (): void {
    $commitment = new BudgetCommitment(['amount' => '1000.00', 'consumed_amount' => '400.00', 'released_amount' => '100.00', 'status' => BudgetCommitment::Open]);
    expect($commitment->openAmount())->toBe('500.00');

    $commitment->consumed_amount = '1200.00';
    expect($commitment->openAmount())->toBe('0.00');

    $commitment->consumed_amount = '0.00';
    $commitment->status = BudgetCommitment::Released;
    expect($commitment->openAmount())->toBe('0.00');
});

test('an override covers what is left of its approved amount until its expiry date included', function (): void {
    $override = new BudgetOverride(['approved_amount' => '1000.00', 'consumed_amount' => '300.00', 'scope' => 'allowance', 'valid_until' => now()->toDateString()]);
    $override->valid_until = Carbon::parse(now()->toDateString());

    expect($override->remaining())->toBe('700.00')->and($override->isExpired())->toBeFalse()->and($override->isDocument())->toBeFalse();

    $override->valid_until = now()->subDay();
    expect($override->isExpired())->toBeTrue();

    $override->consumed_amount = '1500.00';
    expect($override->remaining())->toBe('0.00');

    expect((new BudgetOverride(['approved_amount' => null, 'consumed_amount' => '0.00']))->remaining())->toBe('0.00');
});

test('a rule is more specific the more dimensions it narrows down', function (): void {
    expect((new BudgetSpendRule)->specificity())->toBe(0)
        ->and((new BudgetSpendRule(['cost_center_id' => 'a']))->specificity())->toBe(1)
        ->and((new BudgetSpendRule(['cost_center_id' => 'a', 'project_id' => 'b', 'account_id' => 'c']))->specificity())->toBe(3);
});

test('a decision is blocked only when its outcome says so and carries the check it was logged under', function (): void {
    $availability = new Availability('v1', 2028, true, 'EUR', '100.00', '0.00', '0.00');
    $blocked = new SpendDecision(SpendDecision::Blocked, $availability, '150.00', '50.00', 'rule', 'hard_block', null);

    expect($blocked->isBlocked())->toBeTrue()->and($blocked->withCheck('check-1')->checkId)->toBe('check-1')
        ->and((new SpendDecision(SpendDecision::Warning, $availability, '150.00', '50.00', 'rule', 'warning', null))->isBlocked())->toBeFalse()
        ->and($blocked->toArray())->toMatchArray(['outcome' => 'blocked', 'shortfall' => '50.00', 'requested' => '150.00']);
});

test('a version knows its place in the family around the approved budget', function (): void {
    expect((new BudgetVersion(['version_type' => 'budget']))->isFormalBudget())->toBeTrue()
        ->and((new BudgetVersion(['version_type' => 'revised']))->isFormalBudget())->toBeTrue()
        ->and((new BudgetVersion(['version_type' => 'scenario']))->isFormalBudget())->toBeFalse()
        ->and((new BudgetVersion(['version_type' => 'rolling_forecast']))->isFormalBudget())->toBeFalse()
        ->and((new BudgetVersion(['granularity' => 'quarterly']))->isQuarterly())->toBeTrue()
        ->and(BudgetVersion::Types)->toBe(['budget', 'revised', 'forecast', 'rolling_forecast', 'scenario']);
});
