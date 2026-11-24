<?php

declare(strict_types=1);

namespace Tests\Unit\AssetManagement;

use Carbon\CarbonImmutable;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Services\DepreciationCalculator;
use Modules\AssetManagement\Services\FiscalIncentiveCalculator;
use Modules\AssetManagement\Support\DepreciationPeriod;

test('calculates Swiss AFC declining balance (Buchwert) without switching to straight line and respects pro-memoria floor', function (): void {
    $calculator = new DepreciationCalculator;
    $book = new AssetBook([
        'fiscal_regime' => 'ch_afc_degressive',
        'depreciation_method' => 'declining_balance',
        'useful_life_months' => 60,
        'rate_percent' => '40.0000',
        'pro_rata_convention' => 'full',
        'switch_to_straight_line' => false,
        'cost' => '10000.00',
        'salvage_value' => '0.00',
        'pro_memoria_value' => '1.00',
        'depreciable_base' => '10000.00',
        'accumulated_depreciation' => '0.00',
        'net_book_value' => '10000.00',
    ]);
    $book->depreciation_start_date = CarbonImmutable::parse('2026-01-15');

    $projection = $calculator->project($book, DepreciationPeriod::fromCode('2026', 'yearly'), 10);

    // Pure declining 40%: Y1 = 4,000 (NBV 6,000), Y2 = 2,400 (NBV 3,600), Y3 = 1,440 (NBV 2,160), Y4 = 864 (NBV 1,296), Y5 = 518.40 (NBV 777.60)
    expect($projection[0]['depreciation_amount'])->toBe('4000.00')
        ->and($projection[0]['closing_book_value'])->toBe('6000.00')
        ->and($projection[1]['depreciation_amount'])->toBe('2400.00')
        ->and($projection[1]['closing_book_value'])->toBe('3600.00')
        ->and($projection[2]['depreciation_amount'])->toBe('1440.00')
        ->and($projection[3]['depreciation_amount'])->toBe('864.00')
        ->and($projection[4]['depreciation_amount'])->toBe('518.40')
        ->and($projection[4]['closing_book_value'])->toBe('777.60');
});

test('calculates Swiss AFC immediate expensing (Sofortabschreibung) down to pro-memoria CHF 1.00', function (): void {
    $calculator = new DepreciationCalculator;
    $book = new AssetBook([
        'fiscal_regime' => 'ch_afc_immediate',
        'depreciation_method' => 'immediate_expensing',
        'immediate_expensing' => true,
        'cost' => '950.00',
        'salvage_value' => '0.00',
        'pro_memoria_value' => '1.00',
        'depreciable_base' => '950.00',
        'accumulated_depreciation' => '0.00',
        'net_book_value' => '950.00',
    ]);
    $book->depreciation_start_date = CarbonImmutable::parse('2026-06-01');

    $charge = $calculator->periodCharge($book, DepreciationPeriod::fromCode('2026', 'yearly'));
    expect($charge)->toBe('949.00');
});

test('calculates Italian D.M. 31/12/1988 ministerial rate with 50 percent reduction in first fiscal year', function (): void {
    $calculator = new DepreciationCalculator;
    $book = new AssetBook([
        'fiscal_regime' => 'it_dm_1988',
        'depreciation_method' => 'straight_line',
        'rate_percent' => '20.0000',
        'first_year_half_rate' => true,
        'pro_rata_convention' => 'half_year',
        'useful_life_months' => 60,
        'cost' => '10000.00',
        'salvage_value' => '0.00',
        'depreciable_base' => '10000.00',
        'accumulated_depreciation' => '0.00',
        'net_book_value' => '10000.00',
    ]);
    $book->depreciation_start_date = CarbonImmutable::parse('2026-09-10');

    $projection = $calculator->project($book, DepreciationPeriod::fromCode('2026', 'yearly'), 10);

    // Year 1: 50% of 20% = 1,000; Years 2-5: 2,000; Year 6: remaining 1,000 -> NBV 0.00
    expect($projection)->toHaveCount(6)
        ->and($projection[0]['depreciation_amount'])->toBe('1000.00')
        ->and($projection[1]['depreciation_amount'])->toBe('2000.00')
        ->and($projection[4]['depreciation_amount'])->toBe('2000.00')
        ->and($projection[5]['depreciation_amount'])->toBe('1000.00')
        ->and($projection[5]['closing_book_value'])->toBe('0.00');
});

test('computes Italian Transition 4.0, Transition 5.0, Super and Iper-Ammortamento incentives accurately', function (): void {
    $incentives = new FiscalIncentiveCalculator;

    $t4 = new FixedAsset([
        'acquisition_cost' => '100000.00',
        'capitalized_cost' => '100000.00',
        'incentive_regime' => 'transition_4_0_tangible',
        'incentive_rate_percent' => '20.0000',
        'incentive_installment_years' => 3,
    ]);
    $t4->incentive_interconnection_date = CarbonImmutable::parse('2026-05-01');
    $t4Summary = $incentives->computeSummary($t4);

    expect($t4Summary['eligible'])->toBeTrue()
        ->and($t4Summary['mode'])->toBe('tax_credit')
        ->and($t4Summary['tax_credit_total'])->toBe('20000.00')
        ->and($t4Summary['annual_installments'])->toBe([
            2026 => '6666.67',
            2027 => '6666.67',
            2028 => '6666.66',
        ]);

    $t5 = new FixedAsset([
        'acquisition_cost' => '200000.00',
        'capitalized_cost' => '200000.00',
        'incentive_regime' => 'transition_5_0',
        'incentive_rate_percent' => '35.0000',
        'incentive_installment_years' => 1,
    ]);
    $t5->incentive_interconnection_date = CarbonImmutable::parse('2026-07-01');
    $t5Summary = $incentives->computeSummary($t5);

    expect($t5Summary['tax_credit_total'])->toBe('70000.00')
        ->and($t5Summary['annual_installments'])->toBe([2026 => '70000.00']);

    $iper = new FixedAsset([
        'acquisition_cost' => '50000.00',
        'capitalized_cost' => '50000.00',
        'incentive_regime' => 'iper_ammortamento',
        'incentive_rate_percent' => '150.0000',
    ]);
    $iperSummary = $incentives->computeSummary($iper);

    expect($iperSummary['mode'])->toBe('extra_deduction')
        ->and($iperSummary['extra_deduction_base'])->toBe('75000.00')
        ->and($incentives->extraDeductionForCharge($iper, '5000.00'))->toBe('7500.00');
});
