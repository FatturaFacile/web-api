<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Services\InterestCalculator;

/**
 * A single open-ended rate period, in percent per year.
 *
 * @return list<array{from: Carbon, to: Carbon|null, rate: string}>
 */
function dcFlatRate(string $rate): array
{
    return [['from' => Carbon::parse('1912-01-01'), 'to' => null, 'rate' => $rate]];
}

it('counts the days after the due date up to the calculation date, inclusive, on a 365 basis', function (): void {
    $result = (new InterestCalculator)->calculate('1000.00', Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31'), InterestCalculator::Actual365, dcFlatRate('5'));

    expect($result['days'])->toBe(30)
        ->and($result['interest'])->toBe('4.11') // 1000 x 5% x 30 / 365 = 4.1095...
        ->and($result['segments'])->toHaveCount(1);
});

it('charges nothing on the due date itself or before it', function (): void {
    $calculator = new InterestCalculator;

    expect($calculator->calculate('1000.00', Carbon::parse('2025-03-10'), Carbon::parse('2025-03-10'), InterestCalculator::Actual365, dcFlatRate('5')))
        ->toMatchArray(['interest' => '0.00', 'days' => 0])
        ->and($calculator->calculate('1000.00', Carbon::parse('2025-03-10'), Carbon::parse('2025-03-01'), InterestCalculator::Actual365, dcFlatRate('5'))['interest'])->toBe('0.00');
});

it('uses 360 days on the actual/360 basis', function (): void {
    $result = (new InterestCalculator)->calculate('1000.00', Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31'), InterestCalculator::Actual360, dcFlatRate('5'));

    expect($result['interest'])->toBe('4.17'); // 1000 x 5% x 30 / 360 = 4.1666...
});

it('keeps a full leap year at exactly one year of interest on the 365 basis and below it on actual/actual', function (): void {
    $calculator = new InterestCalculator;
    $due = Carbon::parse('2024-01-01');
    $asOf = Carbon::parse('2024-12-31');

    expect($calculator->calculate('1000.00', $due, $asOf, InterestCalculator::Actual365, dcFlatRate('5'))['interest'])->toBe('50.00')
        ->and($calculator->calculate('1000.00', $due, $asOf, InterestCalculator::ActualActual, dcFlatRate('5'))['interest'])->toBe('49.86'); // 50 x 365 / 366
});

it('splits the period at the turn of the year on the actual/actual basis', function (): void {
    $result = (new InterestCalculator)->calculate('1000.00', Carbon::parse('2024-12-25'), Carbon::parse('2025-01-05'), InterestCalculator::ActualActual, dcFlatRate('5'));

    expect($result['days'])->toBe(11)
        ->and($result['segments'])->toHaveCount(2)
        ->and($result['segments'][0])->toMatchArray(['from' => '2024-12-26', 'to' => '2024-12-31', 'days' => 6])
        ->and($result['segments'][1])->toMatchArray(['from' => '2025-01-01', 'to' => '2025-01-05', 'days' => 5])
        ->and($result['interest'])->toBe('1.50'); // 0.8197 + 0.6849
});

it('applies the ECB rate of each semester when the period crosses a change of rate (D.Lgs. 231/2002)', function (): void {
    $periods = [
        ['from' => Carbon::parse('2023-01-01'), 'to' => Carbon::parse('2023-06-30'), 'rate' => '10.5'],
        ['from' => Carbon::parse('2023-07-01'), 'to' => null, 'rate' => '12'],
    ];

    $result = (new InterestCalculator)->calculate('10000.00', Carbon::parse('2023-06-20'), Carbon::parse('2023-07-10'), InterestCalculator::Actual365, $periods);

    expect($result['days'])->toBe(20)
        ->and($result['segments'])->toHaveCount(2)
        ->and($result['segments'][0])->toMatchArray(['from' => '2023-06-21', 'to' => '2023-06-30', 'days' => 10, 'rate_percent' => '10.5000', 'interest' => '28.77'])
        ->and($result['segments'][1])->toMatchArray(['from' => '2023-07-01', 'to' => '2023-07-10', 'days' => 10, 'rate_percent' => '12.0000', 'interest' => '32.88'])
        // The sum is rounded once (28.7671 + 32.8767 = 61.6438), not piece by piece (28.77 + 32.88 = 61.65).
        ->and($result['interest'])->toBe('61.64')
        ->and($result['rate_percent'])->toBe('12.0000');
});

it('uses the agreed rate when it is higher than the statutory one', function (): void {
    $calculator = new InterestCalculator;
    $due = Carbon::parse('2025-01-01');
    $asOf = Carbon::parse('2025-01-31');

    expect($calculator->calculate('1000.00', $due, $asOf, InterestCalculator::Actual365, dcFlatRate('5'), '9')['interest'])->toBe('7.40') // 1000 x 9% x 30 / 365
        ->and($calculator->calculate('1000.00', $due, $asOf, InterestCalculator::Actual365, dcFlatRate('5'), '3')['interest'])->toBe('4.11'); // a lower agreed rate never beats the statutory one
});

it('rounds a half cent away from zero', function (): void {
    $result = (new InterestCalculator)->calculate('182.50', Carbon::parse('2025-01-01'), Carbon::parse('2025-01-02'), InterestCalculator::Actual365, dcFlatRate('1'));

    expect($result['interest'])->toBe('0.01'); // 182.50 x 1% x 1 / 365 = 0.005 exactly
});

it('refuses a date that no rate period covers', function (): void {
    $periods = [['from' => Carbon::parse('2025-01-01'), 'to' => null, 'rate' => '5']];

    (new InterestCalculator)->calculate('100.00', Carbon::parse('2024-06-01'), Carbon::parse('2025-02-01'), InterestCalculator::Actual365, $periods);
})->throws(DebtCollectionException::class);

it('refuses an unknown basis', function (): void {
    (new InterestCalculator)->calculate('100.00', Carbon::parse('2025-01-01'), Carbon::parse('2025-02-01'), 'actual_366', dcFlatRate('5'));
})->throws(DebtCollectionException::class);
