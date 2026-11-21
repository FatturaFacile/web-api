<?php

declare(strict_types=1);

use Modules\MrpPlanning\Domain\Enums\LotSizingRule;
use Modules\MrpPlanning\Domain\Exceptions\BomCycleException;
use Modules\MrpPlanning\Domain\Services\LotSizingCalculator;
use Modules\MrpPlanning\Domain\Services\LowLevelCodeCalculator;
use Modules\MrpPlanning\Domain\ValueObjects\BomLine;

require_once __DIR__.'/MrpTestSupport.php';

it('gives finished goods level 0 and every component the deepest level it appears at', function (): void {
    // fg -> sub -> raw, and fg -> raw directly: raw must be netted after sub, so it is level 2, not 1.
    $items = [
        'fg' => mrpItem('fg', ['components' => [new BomLine('sub', '1'), new BomLine('raw', '1')]]),
        'sub' => mrpItem('sub', ['components' => [new BomLine('raw', '2')]]),
        'raw' => mrpItem('raw'),
        'alone' => mrpItem('alone'),
    ];

    expect((new LowLevelCodeCalculator)->calculate($items))->toBe(['fg' => 0, 'sub' => 1, 'raw' => 2, 'alone' => 0]);
});

it('ignores components that are not planned items', function (): void {
    $items = ['fg' => mrpItem('fg', ['components' => [new BomLine('phantom-gone', '1')]])];

    expect((new LowLevelCodeCalculator)->calculate($items))->toBe(['fg' => 0]);
});

it('detects a cycle and reports the path instead of looping', function (): void {
    $items = [
        'a' => mrpItem('a', ['components' => [new BomLine('b', '1')]]),
        'b' => mrpItem('b', ['components' => [new BomLine('c', '1')]]),
        'c' => mrpItem('c', ['components' => [new BomLine('a', '1')]]),
    ];

    try {
        (new LowLevelCodeCalculator)->calculate($items);
        $this->fail('Expected a cycle');
    } catch (BomCycleException $exception) {
        expect($exception->path)->toBe(['a', 'b', 'c', 'a'])->and($exception->getMessage())->toContain('a -> b -> c -> a');
    }
});

it('detects an item that is a component of itself', function (): void {
    (new LowLevelCodeCalculator)->calculate(['a' => mrpItem('a', ['components' => [new BomLine('a', '1')]])]);
})->throws(BomCycleException::class);

it('sizes lots by every rule', function (LotSizingRule $rule, array $item, string $net, string $window, string $expected): void {
    $quantity = (new LotSizingCalculator)->orderQuantity(mrpItem('x', ['rule' => $rule, ...$item]), $net, $window);

    expect($quantity)->toBe($expected);
})->with([
    'lot for lot orders exactly the need' => [LotSizingRule::LotForLot, [], '37', '0', '37.0000'],
    'MOQ lifts a small need to the minimum' => [LotSizingRule::MinimumOrderQuantity, ['moq' => '100'], '37', '0', '100.0000'],
    'MOQ keeps a bigger need' => [LotSizingRule::MinimumOrderQuantity, ['moq' => '100'], '130', '0', '130.0000'],
    'multiple rounds up to the next multiple' => [LotSizingRule::OrderMultiple, ['multiple' => '25'], '51', '0', '75.0000'],
    'multiple keeps an exact multiple' => [LotSizingRule::OrderMultiple, ['multiple' => '25'], '50', '0', '50.0000'],
    'multiple respects the minimum first' => [LotSizingRule::OrderMultiple, ['multiple' => '25', 'moq' => '60'], '10', '0', '75.0000'],
    'EOQ orders at least the economic quantity' => [LotSizingRule::EconomicOrderQuantity, ['eoq' => '120'], '40', '0', '120.0000'],
    'EOQ keeps a bigger need' => [LotSizingRule::EconomicOrderQuantity, ['eoq' => '120'], '200', '0', '200.0000'],
    'fixed days covers the whole window' => [LotSizingRule::FixedDaysOfSupply, ['fixed_days' => 7], '40', '95', '95.0000'],
    'fixed days never orders less than the need' => [LotSizingRule::FixedDaysOfSupply, ['fixed_days' => 7], '40', '10', '40.0000'],
]);

it('adds the scrap percentage before the lot rule, so MOQ and multiples hold on the ordered quantity', function (): void {
    $lots = new LotSizingCalculator;

    // 100 needed, 3 % scrap -> 103 must be started; the multiple of 50 then rounds it to 150.
    expect($lots->orderQuantity(mrpItem('x', ['scrap' => '3', 'rule' => LotSizingRule::OrderMultiple, 'multiple' => '50']), '100'))->toBe('150.0000')
        ->and($lots->orderQuantity(mrpItem('x', ['scrap' => '3']), '100'))->toBe('103.0000')
        ->and($lots->goodOutput('103.0000', '3'))->toBe('100.0000')
        ->and($lots->withScrap('10', '12.5'))->toBe('11.2500')
        ->and($lots->withScrap('0.3333', '10'))->toBe('0.3667'); // rounded up, never short
});
