<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Services\PromotionContributionCalculator;

/**
 * @param  array<string, mixed>  $attributes
 */
function gdtPromotion(array $attributes): GdoPromotion
{
    return new GdoPromotion($attributes + [
        'promotion_type' => 'sell_in_flyer', 'date_basis' => 'order', 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31',
        'contribution_mode' => 'percent_of_sales', 'fixed_accrual' => 'linear', 'status' => 'active',
    ]);
}

const GDT_NONE = ['value' => '0.0000', 'quantity' => '0.0000'];

it('takes a percentage of the net sell-in and never a negative contribution', function (): void {
    $calculator = new PromotionContributionCalculator;
    $promotion = gdtPromotion(['rate_percent' => '4']);

    expect($calculator->earned($promotion, ['value' => '50000', 'quantity' => '0'], GDT_NONE, Carbon::parse('2028-03-31')))
        ->toBe(['earned' => '2000.0000', 'base_value' => '50000.0000', 'rate_percent' => '4.0000'])
        // Returns that exceed the sales of the window leave nothing to contribute.
        ->and($calculator->earned($promotion, ['value' => '-300', 'quantity' => '0'], GDT_NONE, Carbon::parse('2028-03-31'))['earned'])->toBe('0.0000');
});

it('pays an amount per unit sold', function (): void {
    $promotion = gdtPromotion(['contribution_mode' => 'per_unit', 'unit_amount' => '0.35']);

    $result = (new PromotionContributionCalculator)->earned($promotion, ['value' => '99999', 'quantity' => '1000'], GDT_NONE, Carbon::parse('2028-06-30'));

    expect($result['earned'])->toBe('350.0000')->and($result['rate_percent'])->toBeNull();
});

it('reads the sell-out the retailer reported, by value or by unit', function (): void {
    $calculator = new PromotionContributionCalculator;
    $byValue = gdtPromotion(['promotion_type' => 'sell_out', 'contribution_mode' => 'percent_of_sell_out', 'rate_percent' => '2']);
    $byUnit = gdtPromotion(['promotion_type' => 'sell_out', 'contribution_mode' => 'percent_of_sell_out', 'rate_percent' => '2', 'unit_amount' => '0.5']);
    $sellOut = ['value' => '80000', 'quantity' => '300'];

    expect($calculator->earned($byValue, GDT_NONE, $sellOut, Carbon::parse('2028-06-30'))['earned'])->toBe('1600.0000')
        ->and($calculator->earned($byUnit, GDT_NONE, $sellOut, Carbon::parse('2028-06-30'))['earned'])->toBe('150.0000');
});

it('accrues a fixed amount linearly over the window, in full at the start, or not before it begins', function (): void {
    $calculator = new PromotionContributionCalculator;
    $linear = gdtPromotion(['contribution_mode' => 'fixed_amount', 'fixed_amount' => '12000']);
    $onStart = gdtPromotion(['contribution_mode' => 'fixed_amount', 'fixed_amount' => '6000', 'fixed_accrual' => 'on_start', 'starts_on' => '2028-05-01', 'ends_on' => '2028-05-31']);

    // 182 of 366 days elapsed on 30 June.
    expect($calculator->earned($linear, GDT_NONE, GDT_NONE, Carbon::parse('2028-06-30'))['earned'])->toBe('5967.2131')
        ->and($calculator->earned($linear, GDT_NONE, GDT_NONE, Carbon::parse('2028-12-31'))['earned'])->toBe('12000.0000')
        ->and($calculator->earned($linear, GDT_NONE, GDT_NONE, Carbon::parse('2027-12-31'))['earned'])->toBe('0.0000')
        ->and($calculator->earned($onStart, GDT_NONE, GDT_NONE, Carbon::parse('2028-04-30'))['earned'])->toBe('0.0000')
        ->and($calculator->earned($onStart, GDT_NONE, GDT_NONE, Carbon::parse('2028-05-01'))['earned'])->toBe('6000.0000');
});

it('limits the contribution to the cap', function (): void {
    $promotion = gdtPromotion(['rate_percent' => '10', 'cap_amount' => '5000']);

    expect((new PromotionContributionCalculator)->earned($promotion, ['value' => '100000', 'quantity' => '0'], GDT_NONE, Carbon::parse('2028-12-31'))['earned'])->toBe('5000.0000');
});
