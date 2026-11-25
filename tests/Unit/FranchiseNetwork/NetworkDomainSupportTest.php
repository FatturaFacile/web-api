<?php

declare(strict_types=1);

use Modules\FranchiseNetwork\Domain\Enums\Jurisdiction;
use Modules\FranchiseNetwork\Domain\Support\AuditScorer;
use Modules\FranchiseNetwork\Domain\Support\Distribution;
use Modules\FranchiseNetwork\Domain\Support\LocalizedText;
use Modules\FranchiseNetwork\Domain\Support\LoyaltySettlementCalculator;
use Modules\FranchiseNetwork\Domain\Support\PricePolicy;

it('summarises a sample with the average, the median and the top quartile threshold', function (): void {
    $summary = Distribution::summarize([40, 10, 30, 20]);

    expect($summary)->toBe(['average' => '25.0000', 'median' => '25.0000', 'p75' => '32.5000', 'sample_size' => 4]);
});

it('summarises a single store and an empty sample', function (): void {
    expect(Distribution::summarize(['7']))->toBe(['average' => '7.0000', 'median' => '7.0000', 'p75' => '7.0000', 'sample_size' => 1])
        ->and(Distribution::summarize([]))->toBe(['average' => null, 'median' => null, 'p75' => null, 'sample_size' => 0]);
});

it('interpolates between the closest ranks for an odd sample', function (): void {
    $summary = Distribution::summarize([1, 2, 3, 4, 5]);

    expect($summary['median'])->toBe('3.0000')->and($summary['p75'])->toBe('4.0000');
});

it('settles redemptions between stores into a net position that adds up to zero', function (): void {
    $positions = LoyaltySettlementCalculator::settle([
        ['store_id' => 'B', 'earned_store_id' => 'A', 'value_amount' => '100.00'],
        ['store_id' => 'A', 'earned_store_id' => 'B', 'value_amount' => '40.00'],
        ['store_id' => 'B', 'earned_store_id' => 'B', 'value_amount' => '50.00'],
        ['store_id' => 'C', 'earned_store_id' => null, 'value_amount' => '10.00'],
    ]);

    expect($positions)->toHaveCount(2)
        ->and($positions['A'])->toBe(['owed' => '40.00', 'receivable' => '100.00', 'net' => '-60.00'])
        ->and($positions['B'])->toBe(['owed' => '100.00', 'receivable' => '40.00', 'net' => '60.00'])
        ->and(bcadd($positions['A']['net'], $positions['B']['net'], 2))->toBe('0.00');
});

it('turns a maximum price into a recommendation for a Swiss store (CO / LCart)', function (): void {
    $swiss = PricePolicy::forJurisdiction(['sku' => 'A', 'price' => 120, 'price_type' => 'maximum'], Jurisdiction::Ch);
    $italian = PricePolicy::forJurisdiction(['sku' => 'A', 'price' => 120, 'price_type' => 'maximum'], Jurisdiction::It);
    $recommended = PricePolicy::forJurisdiction(['sku' => 'A', 'price' => 120], Jurisdiction::It);

    expect($swiss)->toMatchArray(['price_type' => 'recommended', 'non_binding' => true, 'downgraded_to_recommended' => true])
        ->and($italian)->toMatchArray(['price_type' => 'maximum', 'non_binding' => false, 'downgraded_to_recommended' => false])
        ->and($recommended)->toMatchArray(['price_type' => 'recommended', 'non_binding' => true]);
});

it('applies the price policy to every price of a payload and leaves the other rows alone', function (): void {
    $payload = PricePolicy::applyToPayload([
        'items' => [['sku' => 'A', 'name' => 'Jacket', 'price' => 189, 'price_type' => 'maximum'], ['sku' => 'B', 'name' => 'No price']],
        'prices' => [['sku' => 'A', 'price' => 10, 'price_type' => 'maximum']],
        'promotion' => ['name' => 'Sale'],
    ], Jurisdiction::Ch);

    expect($payload['items'][0]['price_type'])->toBe('recommended')
        ->and($payload['items'][1])->toBe(['sku' => 'B', 'name' => 'No price'])
        ->and($payload['prices'][0]['non_binding'])->toBeTrue()
        ->and($payload['promotion'])->toBe(['name' => 'Sale']);
});

it('scores an audit as the weighted percentage of the maximum and passes it against the threshold', function (): void {
    $criteria = [
        ['code' => 'A', 'weight' => 2, 'max_score' => 5, 'critical' => true],
        ['code' => 'B', 'weight' => 1, 'max_score' => 5, 'critical' => false],
    ];

    $perfect = AuditScorer::score($criteria, ['A' => 5, 'B' => 5], 80);
    $weak = AuditScorer::score($criteria, ['A' => 5, 'B' => 0], 80);
    $borderline = AuditScorer::score($criteria, ['A' => 5, 'B' => 2], 60);

    expect($perfect)->toMatchArray(['score' => '3.00', 'max_score' => '3.00', 'score_percent' => '100.00', 'passed' => true, 'weak' => []])
        ->and($weak)->toMatchArray(['score_percent' => '66.67', 'passed' => false, 'weak' => ['B']])
        ->and($borderline)->toMatchArray(['score_percent' => '80.00', 'passed' => true, 'weak' => ['B']]);
});

it('fails an audit with a critical criterion at zero whatever the percentage', function (): void {
    $criteria = [
        ['code' => 'A', 'weight' => 1, 'max_score' => 5, 'critical' => true],
        ['code' => 'B', 'weight' => 9, 'max_score' => 5, 'critical' => false],
    ];

    $result = AuditScorer::score($criteria, ['A' => 0, 'B' => 5], 80);

    expect($result['score_percent'])->toBe('90.00')
        ->and($result['failed_critical'])->toBe(['A'])
        ->and($result['passed'])->toBeFalse();
});

it('keeps a score inside its range and counts a missing answer as zero', function (): void {
    $criteria = [['code' => 'A', 'weight' => 1, 'max_score' => 5], ['code' => 'B', 'weight' => 1, 'max_score' => 5]];

    $result = AuditScorer::score($criteria, ['A' => 9], 50);

    expect($result['score'])->toBe('1.00')->and($result['score_percent'])->toBe('50.00')->and($result['passed'])->toBeTrue();
});

it('picks the label of a locale, then its language, then English, then anything', function (): void {
    $labels = ['it' => 'Ciao', 'en' => 'Hello'];

    expect(LocalizedText::pick($labels, 'it-CH'))->toBe('Ciao')
        ->and(LocalizedText::pick($labels, 'it'))->toBe('Ciao')
        ->and(LocalizedText::pick($labels, 'de'))->toBe('Hello')
        ->and(LocalizedText::pick(['fr' => 'Bonjour'], 'de'))->toBe('Bonjour')
        ->and(LocalizedText::pick(null, 'it'))->toBe('')
        ->and(LocalizedText::pick([], 'it'))->toBe('');
});
