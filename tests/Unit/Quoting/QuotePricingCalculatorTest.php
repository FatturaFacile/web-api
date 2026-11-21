<?php

declare(strict_types=1);

use Modules\Quoting\Domain\Services\QuotePricingCalculator;
use Modules\Quoting\Domain\Services\QuoteRevisionDiffService;
use Modules\Quoting\Domain\ValueObjects\DocumentHash;
use Modules\Quoting\Domain\ValueObjects\QuoteNumber;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function qtgLine(string $id, string $price, array $extra = []): array
{
    return $extra + [
        'id' => $id, 'section_id' => 's1', 'line_type' => 'product', 'selection_mode' => 'mandatory', 'alternative_group' => null,
        'is_default_selected' => true, 'quantity' => '1', 'unit_price' => $price, 'unit_cost' => '0', 'discount_percent' => '0',
        'discount_fixed' => '0', 'tier_prices' => null, 'tax_rate' => '22',
    ];
}

$sections = [['id' => 's1', 'discount_percent' => '0']];

it('prices mandatory lines with Italian IVA and reports net, tax and gross', function () use ($sections): void {
    $result = (new QuotePricingCalculator)->calculate($sections, [qtgLine('a', '100', ['quantity' => '3']), qtgLine('b', '50', ['tax_rate' => '10'])]);

    expect($result['totals'])->toMatchArray(['net' => '350.00', 'tax' => '71.00', 'gross' => '421.00', 'payable' => '421.00', 'rounding' => '0.00'])
        ->and($result['tax_breakdown'])->toBe([
            ['rate' => '10.0000', 'net' => '50.00', 'tax' => '5.00'],
            ['rate' => '22.0000', 'net' => '300.00', 'tax' => '66.00'],
        ]);
});

it('counts an optional line only when toggled on and recalculates the totals', function () use ($sections): void {
    $lines = [qtgLine('a', '100'), qtgLine('opt', '40', ['selection_mode' => 'optional', 'is_default_selected' => false])];
    $calculator = new QuotePricingCalculator;

    expect($calculator->calculate($sections, $lines)['totals']['net'])->toBe('100.00')
        ->and($calculator->calculate($sections, $lines, ['opt' => true])['totals']['net'])->toBe('140.00')
        ->and($calculator->calculate($sections, $lines, ['opt' => true])['totals']['gross'])->toBe('170.80');
});

it('keeps exactly one line of an alternative group: the chosen one, else the default, else the first', function (): void {
    $group = static fn (bool $defaultOnB): array => [
        qtgLine('a', '100', ['selection_mode' => 'alternative_group', 'alternative_group' => 'tier', 'is_default_selected' => ! $defaultOnB]),
        qtgLine('b', '200', ['selection_mode' => 'alternative_group', 'alternative_group' => 'tier', 'is_default_selected' => $defaultOnB]),
    ];
    $calculator = new QuotePricingCalculator;

    expect($calculator->resolveIncluded($group(false), []))->toBe(['a' => true, 'b' => false])
        ->and($calculator->resolveIncluded($group(true), []))->toBe(['a' => false, 'b' => true])
        ->and($calculator->resolveIncluded($group(false), ['b' => true]))->toBe(['a' => false, 'b' => true])
        ->and($calculator->calculate([['id' => 's1']], $group(false), ['b' => true])['totals']['net'])->toBe('200.00');
});

it('cascades line, section and header discounts in that order', function (): void {
    $result = (new QuotePricingCalculator)->calculate(
        [['id' => 's1', 'discount_percent' => '10']],
        [qtgLine('a', '100', ['quantity' => '2', 'discount_percent' => '10', 'discount_fixed' => '5'])],
        [],
        '5',
    );

    // 200 - 20 - 5 = 175; section -10% = 157.50; header -5% = 149.625 -> 149.63
    expect($result['lines']['a']['net'])->toBe('149.63')->and($result['sections']['s1']['subtotal'])->toBe('149.63');
});

it('uses the tier price of the highest threshold reached by the quantity', function (): void {
    $tiers = [['min_qty' => '10', 'unit_price' => '90'], ['min_qty' => '50', 'unit_price' => '80']];
    $calculator = new QuotePricingCalculator;
    $net = static fn (string $qty): string => $calculator->calculate([['id' => 's1']], [qtgLine('a', '100', ['quantity' => $qty, 'tier_prices' => $tiers])])['totals']['net'];

    expect($net('5'))->toBe('500.00')->and($net('10'))->toBe('900.00')->and($net('60'))->toBe('4800.00');
});

it('rounds the Swiss payable total to 0.05 and reports the adjustment apart', function (): void {
    // 33.33 net at 8.1% = 2.70 tax -> 36.03 gross -> 36.05 payable
    $result = (new QuotePricingCalculator)->calculate([['id' => 's1']], [qtgLine('a', '33.33', ['tax_rate' => '8.1'])], [], '0', '0.05');

    expect($result['totals'])->toMatchArray(['net' => '33.33', 'tax' => '2.70', 'gross' => '36.03', 'payable' => '36.05', 'rounding' => '0.02']);
});

it('computes the margin on the included lines only', function () use ($sections): void {
    $lines = [qtgLine('a', '100', ['unit_cost' => '60']), qtgLine('opt', '100', ['unit_cost' => '90', 'selection_mode' => 'optional', 'is_default_selected' => false])];
    $calculator = new QuotePricingCalculator;

    expect($calculator->calculate($sections, $lines)['totals'])->toMatchArray(['cost' => '60.00', 'margin' => '40.00', 'margin_percent' => '40.00'])
        ->and($calculator->calculate($sections, $lines, ['opt' => true])['totals']['margin_percent'])->toBe('25.00');
});

it('gives text notes no price and a subtotal row the running section total', function () use ($sections): void {
    $lines = [qtgLine('a', '100'), qtgLine('note', '0', ['line_type' => 'text_note']), qtgLine('sub', '0', ['line_type' => 'subtotal']), qtgLine('b', '50')];
    $result = (new QuotePricingCalculator)->calculate($sections, $lines);

    expect($result['lines']['sub']['running_subtotal'])->toBe('100.00')->and($result['totals']['net'])->toBe('150.00');
});

it('detects added, removed and modified lines and the total variance between two revisions', function (): void {
    $calculator = new QuotePricingCalculator;
    $from = ['sections' => [['id' => 's1', 'origin_id' => 's1', 'title' => 'Hardware', 'discount_percent' => '0']], 'lines' => [
        qtgLine('l1', '100', ['origin_id' => 'l1', 'description' => 'Server']), qtgLine('l2', '20', ['origin_id' => 'l2', 'description' => 'Cable']),
    ]];
    $to = ['sections' => [['id' => 'n1', 'origin_id' => 's1', 'title' => 'Hardware', 'discount_percent' => '0'], ['id' => 'n2', 'origin_id' => 'n2', 'title' => 'Services', 'discount_percent' => '0']], 'lines' => [
        qtgLine('m1', '100', ['origin_id' => 'l1', 'description' => 'Server', 'quantity' => '2', 'selection_mode' => 'optional']), qtgLine('m3', '30', ['origin_id' => 'm3', 'description' => 'Setup']),
    ]];
    $from['totals'] = $calculator->calculate($from['sections'], $from['lines'])['totals'];
    $to['totals'] = $calculator->calculate($to['sections'], $to['lines'])['totals'];

    $diff = (new QuoteRevisionDiffService)->diff($from, $to);

    expect(array_column($diff['lines']['added'], 'description'))->toBe(['Setup'])
        ->and(array_column($diff['lines']['removed'], 'description'))->toBe(['Cable'])
        ->and($diff['lines']['modified'])->toHaveCount(1)
        ->and(array_keys($diff['lines']['modified'][0]['changes']))->toEqualCanonicalizing(['quantity', 'selection_mode'])
        ->and(array_column($diff['sections']['added'], 'title'))->toBe(['Services'])
        ->and($diff['sections']['modified'])->toBe([])
        ->and($diff['totals']['net']['delta'])->toBe('110.00');
});

it('builds and parses quote numbers with the letter alias of the revision', function (): void {
    $number = new QuoteNumber('OFF', 2026, 123);

    expect((string) $number)->toBe('OFF-2026-00123.01')
        ->and((string) $number->nextRevision())->toBe('OFF-2026-00123.02')
        ->and(QuoteNumber::parse('OFF-2026-00123.03')->revision)->toBe(3)
        ->and((new QuoteNumber('OFF', 2026, 1, 28))->revisionAlias())->toBe('Rev. AB')
        ->and($number->revisionAlias())->toBe('Rev. A');
});

it('gives the same hash to the same payload whatever the key order', function (): void {
    expect(DocumentHash::ofPayload(['b' => 1, 'a' => ['y' => 2, 'x' => 3]])->value)->toBe(DocumentHash::ofPayload(['a' => ['x' => 3, 'y' => 2], 'b' => 1])->value)
        ->and(fn () => new DocumentHash('xyz'))->toThrow(InvalidArgumentException::class);
});
