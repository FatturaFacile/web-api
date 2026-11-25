<?php

declare(strict_types=1);

use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\ValueObjects\OfferInput;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Services\BidBondCalculator;
use Modules\TenderManagement\Services\OfferSimulator;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

require_once __DIR__.'/TenderUnitSupport.php';

function tndInput(string $discount = '10', array $overrides = []): OfferInput
{
    return new OfferInput(
        $discount,
        $overrides['cost'] ?? '700000',
        '0',
        array_key_exists('safety', $overrides) ? $overrides['safety'] : '15000',
        array_key_exists('labor', $overrides) ? $overrides['labor'] : '320000',
        $overrides['base'] ?? '2.00',
        $overrides['technical'] ?? '60',
        $overrides['best'] ?? null,
        $overrides['competitors'] ?? [],
    );
}

function tndSimulator(): OfferSimulator
{
    return app(OfferSimulator::class);
}

it('keeps the safety costs not subject to the discount out of the reduction and reports the net margin', function (): void {
    $result = tndSimulator()->simulate(tndNotice(), tndInput('10'), tndVault([]), tndToday());

    // 980000 discountable base, 10 % off = 882000, plus 20000 of safety costs that cannot be reduced.
    expect($result['discountable_base'])->toBe('980000.00')
        ->and($result['offered_amount'])->toBe('902000.00')
        ->and($result['net_margin_amount'])->toBe('202000.00')
        ->and($result['net_margin_percentage'])->toBe('22.3947')
        ->and($result['submission_blocked'])->toBeFalse();
});

it('blocks an Italian offer without the company safety and labour costs (Art. 108 co. 9)', function (): void {
    $result = tndSimulator()->simulate(tndNotice(), tndInput('10', ['safety' => '0', 'labor' => null]), tndVault([]), tndToday());
    $codes = array_column($result['issues'], 'code');

    expect($result['submission_blocked'])->toBeTrue()
        ->and($codes)->toContain('art108_safety_costs_missing')
        ->and($codes)->toContain('art108_labor_costs_missing');
});

it('warns when the declared labour cost is below the estimate of the contracting authority', function (): void {
    $result = tndSimulator()->simulate(tndNotice(), tndInput('10', ['labor' => '250000']), tndVault([]), tndToday());

    expect(array_column($result['issues'], 'code'))->toContain('art108_labor_costs_below_estimate')
        ->and($result['submission_blocked'])->toBeFalse();
});

it('does not require the Italian cost split on a Swiss tender', function (): void {
    $notice = tndNotice(['jurisdiction' => 'CH_SIMAP_LAPub', 'currency' => 'CHF', 'award_criterion' => 'LowestPrice', 'formula_type' => null]);
    $result = tndSimulator()->simulate($notice, tndInput('5', ['safety' => null, 'labor' => null, 'cost' => '600000']), tndVault([]), tndToday());

    expect($result['submission_blocked'])->toBeFalse()
        ->and($result['scoring']['criterion'])->toBe('LowestPrice')
        ->and($result['anac_contribution_amount'])->toBeNull();
});

it('scores the economic offer with the linear formula against the best competitor', function (): void {
    $result = tndSimulator()->simulate(tndNotice(['formula_type' => 'Linear']), tndInput('10', ['best' => '20']), tndVault([]), tndToday());

    expect($result['scoring']['economic_score'])->toBe('15.00')
        ->and($result['scoring']['technical_score'])->toBe('60.00')
        ->and($result['scoring']['total_score'])->toBe('75.00')
        ->and($result['scoring']['best_discount_assumed'])->toBe('20.0000');
});

it('scores the bilinear formula below and above the average discount of the competitors', function (): void {
    $notice = tndNotice(['formula_type' => 'Bilinear']);
    $competitors = ['10', '20', '30'];

    // Average discount 20: an offer at 10 gets 30 x 0.85 x 10/20 = 12.75.
    $below = tndSimulator()->simulate($notice, tndInput('10', ['competitors' => $competitors]), tndVault([]), tndToday());
    // At 30 (the best) the score reaches the maximum: 30 x (0.85 + 0.15 x 1).
    $best = tndSimulator()->simulate($notice, tndInput('30', ['competitors' => $competitors]), tndVault([]), tndToday());

    expect($below['scoring']['economic_score'])->toBe('12.75')
        ->and($best['scoring']['economic_score'])->toBe('30.00');
});

it('scores the interpolation formula on the offered price', function (): void {
    $notice = tndNotice(['formula_type' => 'Interpolation']);
    // A competitor offering a 20 % discount undercuts a 10 % discount: 30 x 804000 / 902000 = 26.74.
    $result = tndSimulator()->simulate($notice, tndInput('10', ['competitors' => ['20']]), tndVault([]), tndToday());

    expect($result['scoring']['economic_score'])->toBe('26.74');
});

it('flags an OEPV offer reaching four fifths of both maximum scores as potentially anomalous', function (): void {
    $notice = tndNotice(['formula_type' => 'Linear']);
    $anomalous = tndSimulator()->simulate($notice, tndInput('20', ['technical' => '60', 'best' => '20']), tndVault([]), tndToday());
    $regular = tndSimulator()->simulate($notice, tndInput('20', ['technical' => '40', 'best' => '20']), tndVault([]), tndToday());

    expect($anomalous['scoring']['anomaly']['is_anomalous'])->toBeTrue()
        ->and(array_column($anomalous['issues'], 'code'))->toContain('anomaly_threshold_exceeded')
        ->and($regular['scoring']['anomaly']['is_anomalous'])->toBeFalse();
});

it('derives the lowest price anomaly threshold from the competitor discounts', function (): void {
    $notice = tndNotice(['award_criterion' => 'LowestPrice', 'formula_type' => null]);
    // Mean 20, discounts above the mean are 30 (+10): threshold 30.
    $inside = tndSimulator()->simulate($notice, tndInput('25', ['competitors' => ['10', '20', '30']]), tndVault([]), tndToday());
    $outside = tndSimulator()->simulate($notice, tndInput('35', ['competitors' => ['10', '20', '30']]), tndVault([]), tndToday());

    expect($inside['scoring']['anomaly']['is_anomalous'])->toBeFalse()
        ->and($inside['scoring']['anomaly']['threshold_value'])->toBe('30.0000')
        ->and($outside['scoring']['anomaly']['is_anomalous'])->toBeTrue();
});

it('requires a formula for the most economically advantageous tender', function (): void {
    tndSimulator()->simulate(tndNotice(['formula_type' => null]), tndInput('10'), tndVault([]), tndToday());
})->throws(TenderException::class, 'formula_required');

it('rejects a discount outside zero to one hundred percent', function (): void {
    tndSimulator()->simulate(tndNotice(), tndInput('101'), tndVault([]), tndToday());
})->throws(TenderException::class, 'discount_out_of_range');

it('warns when the offer does not cover the cost', function (): void {
    $result = tndSimulator()->simulate(tndNotice(), tndInput('30', ['cost' => '900000']), tndVault([]), tndToday());

    expect(array_column($result['issues'], 'code'))->toContain('offer_below_cost');
});

it('computes the provisional bid bond with the cumulative Art. 106 reductions', function (): void {
    $vault = tndVault([tndDoc('IT_ISO9001'), tndDoc('IT_ISO14001'), tndDoc('IT_UNI_PdR_125_ParitaGenere')]);
    $bond = app(BidBondCalculator::class)->calculate(tndNotice(), app(TenderStrategyRegistry::class)->for(tndNotice()->jurisdiction), $vault, '2.00', tndToday());

    // 2 % of 1,000,000 = 20,000; ISO 9001 (-30 %), ISO 14001 (-20 %) and PdR 125 (-10 %) multiply: 20,000 x 0.7 x 0.8 x 0.9 = 10,080.
    expect($bond['base_amount'])->toBe('20000.00')
        ->and($bond['final_amount'])->toBe('10080.00')
        ->and($bond['total_reduction_percentage'])->toBe('49.6000')
        ->and(array_column($bond['reductions'], 'key'))->toBe(['iso9001', 'iso14001', 'pdr125']);
});

it('applies only the larger of EMAS and ISO 14001 and ignores expired certificates', function (): void {
    $vault = tndVault([
        tndDoc('IT_EMAS'), tndDoc('IT_ISO14001'),
        tndDoc('IT_ISO9001', ['issue_date' => '2020-01-01', 'expiry_date' => '2024-01-01']),
        tndDoc('IT_PMI_Status'),
    ]);
    $bond = app(BidBondCalculator::class)->calculate(tndNotice(), app(TenderStrategyRegistry::class)->for(tndNotice()->jurisdiction), $vault, '1.00', tndToday());

    // 1 % = 10,000; PMI (-50 %) and EMAS (-30 %): 10,000 x 0.5 x 0.7 = 3,500.
    expect($bond['final_amount'])->toBe('3500.00')
        ->and(array_column($bond['reductions'], 'key'))->toEqualCanonicalizing(['pmi', 'emas']);
});

it('grants no reduction on a Swiss tender and refuses a base percentage other than 1 or 2', function (): void {
    $notice = tndNotice(['jurisdiction' => 'CH_SIMAP_LAPub']);
    $strategy = app(TenderStrategyRegistry::class)->for($notice->jurisdiction);
    $bond = app(BidBondCalculator::class)->calculate($notice, $strategy, tndVault([tndDoc('IT_ISO9001')]), '2.00', tndToday());

    expect($bond['reductions'])->toBe([])
        ->and($bond['final_amount'])->toBe('20000.00');

    app(BidBondCalculator::class)->calculate($notice, $strategy, tndVault([]), '3.00', tndToday());
})->throws(TenderException::class, 'bid_bond_percentage_invalid');

it('does arithmetic on exact decimal strings', function (): void {
    expect(Dec::add('0.1', '0.2'))->toBe('0.3000000000')
        ->and(Dec::money('2.345'))->toBe('2.35')
        ->and(Dec::money('-2.345'))->toBe('-2.35')
        ->and(Dec::div('1', '0'))->toBe('0.0000000000')
        ->and(Dec::median(['1', '3', '2', '10']))->toBe('2.5000000000');
});
