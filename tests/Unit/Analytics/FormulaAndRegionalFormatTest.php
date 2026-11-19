<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Analytics\Calculation\CalculatedFields;
use Modules\Analytics\Calculation\FormulaParser;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Scheduling\CronSchedule;

test('the formula parser accepts only numbers, measure keys, operators, parentheses and the whitelisted functions', function (): void {
    $parser = new FormulaParser;

    expect($parser->references('(revenue - cost) / NULLIF(revenue, 0)'))->toBe(['revenue', 'cost'])
        ->and($parser->references('ROUND(ABS(a) * 1.5, 2) + COALESCE(b, c, 0)'))->toBe(['a', 'b', 'c'])
        ->and($parser->parse('-a + 2')[0])->toBe('bin');
});

test('formulas with anything else are refused: sql, strings, unknown functions, bad arity, deep nesting', function (string $formula): void {
    expect(fn () => (new FormulaParser)->parse($formula))->toThrow(InvalidArgumentException::class);
})->with([
    'statement separator' => 'a; DROP TABLE invoices',
    'subquery' => 'a + (SELECT 1)',
    'string literal' => "a + 'x'",
    'hash comment' => 'a # comment',
    'unknown function' => 'SLEEP(10)',
    'raw sql function' => 'pg_sleep(5) + a',
    'dot access' => 'invoices.total_net + 1',
    'upper case reference' => 'Net_Amount + 1',
    'bad arity' => 'ABS(a, b)',
    'unbalanced' => '(a + 1',
    'empty' => '',
    'dangling operator' => 'a +',
    'too long' => str_repeat('a + ', 200).'a',
    'too deep' => str_repeat('(', 40).'a'.str_repeat(')', 40),
]);

test('calculated fields evaluate on aggregated values and never fail on a zero or an empty cell', function (): void {
    $fields = new CalculatedFields;
    $declared = $fields->validate([
        ['key' => 'margin_pct', 'label' => 'Margin %', 'formula' => '(revenue - cost) / NULLIF(revenue, 0) * 100', 'format' => 'percentage'],
        ['key' => 'rounded', 'label' => 'Rounded', 'formula' => 'ROUND(revenue / 3, 2)', 'format' => 'currency'],
        ['key' => 'safe', 'label' => 'Safe', 'formula' => 'COALESCE(missing_value, 7) + ABS(cost - revenue)', 'format' => 'number'],
        ['key' => 'ratio_of_ratio', 'label' => 'Ratio', 'formula' => 'margin_pct / 2', 'format' => 'number'],
    ], ['revenue', 'cost', 'missing_value']);

    $row = $fields->apply(['revenue' => '200.00', 'cost' => '150.00', 'missing_value' => null], $declared);

    expect($row['margin_pct'])->toBe('25.000000')
        ->and($row['rounded'])->toBe('66.67')
        ->and($row['safe'])->toBe('57.000000')
        ->and($row['ratio_of_ratio'])->toBe('12.500000');

    $zero = $fields->apply(['revenue' => '0.00', 'cost' => '10.00', 'missing_value' => null], $declared);
    expect($zero['margin_pct'])->toBeNull()->and($zero['ratio_of_ratio'])->toBeNull();

    $division = $fields->validate([['key' => 'broken', 'formula' => 'revenue / 0', 'format' => 'number']], ['revenue']);
    expect($fields->apply(['revenue' => '5'], $division)['broken'])->toBeNull();
});

test('calculated field declarations are validated against the selected measures and reserved keys', function (): void {
    $fields = new CalculatedFields;

    expect(fn () => $fields->validate([['key' => 'x', 'formula' => 'unknown_measure * 2', 'format' => 'number']], ['revenue']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $fields->validate([['key' => 'revenue', 'formula' => 'revenue * 2', 'format' => 'number']], ['revenue']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $fields->validate([['key' => 'x', 'formula' => 'revenue', 'format' => 'bitcoin']], ['revenue']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $fields->validate([['key' => 'customer', 'formula' => 'revenue', 'format' => 'number']], ['revenue'], ['customer']))->toThrow(InvalidArgumentException::class);
});

test('numbers and dates follow the regional conventions, from decimal strings', function (): void {
    $swiss = new RegionalReportFormatter('it-CH');
    $italian = new RegionalReportFormatter('it-IT');
    $english = new RegionalReportFormatter('en');

    expect($swiss->number('1234.5', 2))->toBe("1'234.50")
        ->and($swiss->number('-1234567.891', 2))->toBe("-1'234'567.89")
        ->and($italian->number('1234.5', 2))->toBe('1.234,50')
        ->and($italian->number('1234567', 0))->toBe('1.234.567')
        ->and($english->number('1234.5', 2))->toBe('1,234.50')
        ->and($english->number('0.5', 2))->toBe('0.50')
        ->and($english->number('-0.001', 2))->toBe('0.00')
        ->and($swiss->date('2027-03-15'))->toBe('15.03.2027')
        ->and((new RegionalReportFormatter('de-CH'))->date('2027-03-15'))->toBe('15.03.2027')
        ->and($italian->date('2027-03-15'))->toBe('15/03/2027')
        ->and($english->date('2027-03-15'))->toBe('2027-03-15')
        ->and($swiss->money('1234.5', 'chf'))->toBe("CHF 1'234.50")
        ->and($italian->money('1234.5', 'EUR'))->toBe('1.234,50 €')
        ->and($swiss->delimiter())->toBe(';')->and($italian->delimiter())->toBe(';')->and($english->delimiter())->toBe(',');
});

test('user locales map to a supported regional locale', function (): void {
    expect(RegionalReportFormatter::normalise('it'))->toBe('it-IT')
        ->and(RegionalReportFormatter::normalise('de'))->toBe('de-CH')
        ->and(RegionalReportFormatter::normalise('fr_CH'))->toBe('fr-CH')
        ->and(RegionalReportFormatter::normalise('en-US'))->toBe('en')
        ->and(RegionalReportFormatter::normalise(null))->toBe('en')
        ->and(RegionalReportFormatter::normalise('it-CH'))->toBe('it-CH');
});

test('headers are translated into the language of the locale with the label as fallback', function (): void {
    $column = ['key' => 'net_amount', 'label' => 'Net amount', 'kind' => 'measure'];

    expect((new RegionalReportFormatter('it-CH'))->header('sales', $column))->toBe('Importo netto')
        ->and((new RegionalReportFormatter('de-CH'))->header('sales', $column))->toBe('Nettobetrag')
        ->and((new RegionalReportFormatter('fr-CH'))->header('sales', $column))->toBe('Montant net')
        ->and((new RegionalReportFormatter('en'))->header('sales', $column))->toBe('Net amount')
        ->and((new RegionalReportFormatter('it-IT'))->header('sales', ['key' => 'net_amount__previous', 'label' => 'Net amount (previous)']))->toBe('Importo netto (precedente)')
        ->and((new RegionalReportFormatter('it-IT'))->header('sales', ['key' => 'my_custom', 'label' => 'Custom label']))->toBe('Custom label');
});

test('cron expressions are validated, bounded and computed on the wall clock of the time zone', function (): void {
    $cron = new CronSchedule;
    $cron->assertValid('0 7 * * 1', 'Europe/Zurich');

    // 07:00 in Zurich is 06:00 UTC in winter and 05:00 UTC in summer time.
    expect($cron->nextRunAfter('0 7 * * 1', 'Europe/Zurich', CarbonImmutable::parse('2027-03-15 10:00:00', 'UTC'))->toIso8601String())->toBe('2027-03-22T06:00:00+00:00')
        ->and($cron->nextRunAfter('0 7 * * 1', 'Europe/Zurich', CarbonImmutable::parse('2027-04-01 10:00:00', 'UTC'))->toIso8601String())->toBe('2027-04-05T05:00:00+00:00')
        ->and($cron->preview('0 7 * * 1', 'Europe/Zurich', 3))->toHaveCount(3);

    foreach (['61 * * * *', 'not a cron', '* * * * *', '*/2 * * * *', '0 7 * * 1; rm -rf /'] as $bad) {
        expect(fn () => $cron->assertValid($bad, 'Europe/Zurich'))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => $cron->assertValid('0 7 * * 1', 'Mars/Olympus'))->toThrow(InvalidArgumentException::class);
});
