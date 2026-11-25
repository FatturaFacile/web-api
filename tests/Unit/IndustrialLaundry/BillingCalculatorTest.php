<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\BillingLineKind;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\ParContractLine;
use Modules\IndustrialLaundry\Services\BillingCalculator;

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>  $lines
 */
function ilrContract(array $attributes, array $lines): ParContract
{
    $contract = new ParContract($attributes + [
        'billing_model' => 'per_piece_washed', 'kg_basis' => 'clean', 'tax_rate' => '22', 'jurisdiction' => 'IT', 'currency' => 'EUR',
        'unreturned_threshold_days' => 60, 'unreturned_garment_replacement_price' => '0',
    ]);
    $contract->setRelation('lines', collect(array_map(static fn (array $line): ParContractLine => new ParContractLine($line + [
        'circulating_stock_qty' => 0, 'weekly_min_billing_percentage' => '0', 'unit_wash_price' => '0', 'price_per_kg' => '0', 'rental_price' => '0', 'replacement_price' => '0',
    ]), $lines)));

    return $contract;
}

/**
 * @param  list<array<string, mixed>>  $delivered
 * @param  array<string, int>  $active
 * @param  list<array{id: string, epc: string, article_type: string}>  $unreturned
 * @return array{delivered: list<array{article_type: string, at: Carbon}>, net_kg: string, active_garments: array<string, int>, unreturned: list<array{id: string, epc: string, article_type: string}>}
 */
function ilrFacts(array $delivered = [], string $netKg = '0', array $active = [], array $unreturned = []): array
{
    return [
        'delivered' => array_map(static fn (array $entry): array => ['article_type' => (string) $entry['article_type'], 'at' => Carbon::parse((string) $entry['at'])], $delivered),
        'net_kg' => $netKg,
        'active_garments' => $active,
        'unreturned' => $unreturned,
    ];
}

it('bills the pieces washed, tops the weeks up to the guaranteed minimum and charges the unreturned garments', function (): void {
    $contract = ilrContract([], [[
        'article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'weekly_min_billing_percentage' => '50', 'unit_wash_price' => '1.00', 'replacement_price' => '20',
    ]]);
    $delivered = array_fill(0, 12, ['article_type' => 'flat_linen', 'at' => '2028-02-07 10:00:00']);

    $result = (new BillingCalculator)->calculate(
        $contract,
        ilrFacts($delivered, '0', [], [['id' => 'g1', 'epc' => 'E28011600000000000000001', 'article_type' => 'flat_linen']]),
        Carbon::parse('2028-02-01'),
        Carbon::parse('2028-02-29'),
    );

    // Weeks of February 2028: 1-6 (6 days, minimum 5), 7-13 (12 delivered), 14-20 (5), 21-27 (5), 28-29 (2 days, minimum 2).
    expect(collect($result['lines'])->map(fn (array $line): string => $line['kind']->value.':'.$line['quantity'].':'.$line['amount'])->all())
        ->toBe(['wash_piece:12:12.00', 'minimum_top_up:17:17.00', 'unreturned:1:20.00'])
        ->and($result['washed_pieces'])->toBe(12)
        ->and($result['wash_amount'])->toBe('12.00')
        ->and($result['minimum_top_up_amount'])->toBe('17.00')
        ->and($result['unreturned_amount'])->toBe('20.00')
        ->and($result['subtotal'])->toBe('49.00')
        ->and($result['tax_amount'])->toBe('10.78')
        ->and($result['total'])->toBe('59.78');
});

it('prorates the guaranteed minimum of a partial week and rounds it up to whole pieces', function (): void {
    $line = new ParContractLine(['article_type' => 'flat_linen', 'circulating_stock_qty' => 100, 'weekly_min_billing_percentage' => '60']);
    $calculator = new BillingCalculator;

    expect($calculator->weeklyMinimum($line, '60', 7))->toBe(60)
        ->and($calculator->weeklyMinimum($line, '60', 3))->toBe(26)
        ->and($calculator->weeklyMinimum($line, '0', 7))->toBe(0);
});

it('uses the seasonal minimum percentage inside the season months, also across the new year', function (): void {
    $calculator = new BillingCalculator;
    $summer = new ParContractLine(['article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'weekly_min_billing_percentage' => '50', 'seasonal_min_billing_percentage' => '100', 'season_start_month' => 6, 'season_end_month' => 9]);
    $winter = new ParContractLine(['article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'weekly_min_billing_percentage' => '50', 'seasonal_min_billing_percentage' => '100', 'season_start_month' => 11, 'season_end_month' => 2]);

    // 2028-07-03 is a Monday: a full week of nothing delivered.
    expect($calculator->topUpPieces($summer, [], Carbon::parse('2028-07-03'), Carbon::parse('2028-07-09')))->toBe(10)
        ->and($calculator->topUpPieces($summer, [], Carbon::parse('2028-02-07'), Carbon::parse('2028-02-13')))->toBe(5)
        ->and($calculator->topUpPieces($winter, [], Carbon::parse('2028-01-10'), Carbon::parse('2028-01-16')))->toBe(10)
        ->and($calculator->topUpPieces($winter, [], Carbon::parse('2028-05-08'), Carbon::parse('2028-05-14')))->toBe(5);
});

it('does not top up a week that already reached the minimum', function (): void {
    $line = new ParContractLine(['article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'weekly_min_billing_percentage' => '50']);
    $deliveries = array_fill(0, 5, Carbon::parse('2028-02-08 09:00:00'));

    expect((new BillingCalculator)->topUpPieces($line, $deliveries, Carbon::parse('2028-02-07'), Carbon::parse('2028-02-13')))->toBe(0);
});

it('bills the net kg of a per kg contract at the rate of the article types weighted by the pieces delivered', function (): void {
    $contract = ilrContract(['billing_model' => 'per_kg_dirty_or_clean'], [
        ['article_type' => 'flat_linen', 'price_per_kg' => '3.00'],
        ['article_type' => 'terry_towel', 'price_per_kg' => '5.00'],
    ]);
    $delivered = [...array_fill(0, 3, ['article_type' => 'flat_linen', 'at' => '2028-02-07']), ['article_type' => 'terry_towel', 'at' => '2028-02-08']];

    $weighted = (new BillingCalculator)->calculate($contract, ilrFacts($delivered, '100'), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'));
    $plain = (new BillingCalculator)->calculate($contract, ilrFacts([], '100'), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'));

    expect($weighted['lines'])->toHaveCount(1)
        ->and($weighted['lines'][0]['kind'])->toBe(BillingLineKind::WashKg)
        ->and($weighted['lines'][0]['quantity'])->toBe('100.000')
        ->and($weighted['lines'][0]['unit_price'])->toBe('3.5000')
        ->and($weighted['wash_amount'])->toBe('350.00')
        ->and($plain['lines'][0]['unit_price'])->toBe('4.0000')
        ->and($plain['wash_amount'])->toBe('400.00');
});

it('bills nothing by weight when nothing was weighed or no rate is set', function (): void {
    $contract = ilrContract(['billing_model' => 'per_kg_dirty_or_clean'], [['article_type' => 'flat_linen', 'price_per_kg' => '3.00']]);
    $free = ilrContract(['billing_model' => 'per_kg_dirty_or_clean'], [['article_type' => 'flat_linen']]);

    expect((new BillingCalculator)->calculate($contract, ilrFacts([], '0'), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'))['lines'])->toBe([])
        ->and((new BillingCalculator)->calculate($free, ilrFacts([], '50'), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'))['lines'])->toBe([]);
});

it('bills the monthly rental of the garments in circulation, prorated for a partial month', function (): void {
    $contract = ilrContract(['billing_model' => 'fixed_monthly_rental_per_garment'], [
        ['article_type' => 'personal_workwear_uniform', 'rental_price' => '4.50'],
        ['article_type' => 'ppe_safety_garment', 'rental_price' => '6.00'],
    ]);
    $active = ['personal_workwear_uniform' => 40, 'ppe_safety_garment' => 20];

    $full = (new BillingCalculator)->calculate($contract, ilrFacts([], '0', $active), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'));
    $half = (new BillingCalculator)->calculate($contract, ilrFacts([], '0', $active), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-14'));

    expect($full['rental_amount'])->toBe('300.00')
        ->and($full['lines'])->toHaveCount(2)
        ->and($full['lines'][0]['detail']['fraction'])->toBe('1')
        ->and($full['washed_pieces'])->toBe(0)
        ->and($half['lines'][0]['detail']['fraction'])->toBe('0.4828')
        ->and($half['rental_amount'])->toBe('144.84');
});

it('bills rental and wash together in the hybrid model and falls back to the contract replacement price', function (): void {
    $contract = ilrContract(['billing_model' => 'hybrid_rental_plus_wash', 'unreturned_garment_replacement_price' => '20.00', 'tax_rate' => '8.1', 'jurisdiction' => 'CH', 'currency' => 'CHF'], [[
        'article_type' => 'hospital_scrub', 'unit_wash_price' => '0.35', 'rental_price' => '1.10',
    ]]);

    $result = (new BillingCalculator)->calculate(
        $contract,
        ilrFacts(array_fill(0, 100, ['article_type' => 'hospital_scrub', 'at' => '2028-02-07']), '0', ['hospital_scrub' => 300], [
            ['id' => 'g1', 'epc' => 'E28011600000000000000001', 'article_type' => 'hospital_scrub'],
            ['id' => 'g2', 'epc' => 'E28011600000000000000002', 'article_type' => 'hospital_scrub'],
        ]),
        Carbon::parse('2028-02-01'),
        Carbon::parse('2028-02-29'),
    );

    expect(collect($result['lines'])->pluck('kind')->map(fn (BillingLineKind $kind): string => $kind->value)->all())->toBe(['wash_piece', 'rental', 'unreturned'])
        ->and($result['wash_amount'])->toBe('35.00')
        ->and($result['rental_amount'])->toBe('330.00')
        ->and($result['unreturned_amount'])->toBe('40.00')
        ->and($result['lines'][2]['detail']['epcs'])->toBe(['E28011600000000000000001', 'E28011600000000000000002'])
        ->and($result['subtotal'])->toBe('405.00')
        ->and($result['tax_amount'])->toBe('32.81')
        ->and($result['total'])->toBe('437.81');
});

it('produces no line and a zero total when there is nothing to bill', function (): void {
    $result = (new BillingCalculator)->calculate(ilrContract([], [['article_type' => 'flat_linen', 'unit_wash_price' => '1.00']]), ilrFacts(), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'));

    expect($result['lines'])->toBe([])
        ->and($result['subtotal'])->toBe('0.00')
        ->and($result['total'])->toBe('0.00');
});
