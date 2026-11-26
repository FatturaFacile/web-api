<?php

declare(strict_types=1);

use Modules\Workflow\Services\HolidayCalendarFactory;

beforeEach(function (): void {
    $this->factory = new HolidayCalendarFactory;
    $this->dates = fn (array $holidays): array => array_column($holidays, 'date');
});

test('easter sunday follows the computus for the years around the tests', function (): void {
    expect($this->factory->easter(2026)->toDateString())->toBe('2026-04-05')
        ->and($this->factory->easter(2027)->toDateString())->toBe('2027-03-28')
        ->and($this->factory->easter(2028)->toDateString())->toBe('2028-04-16');
});

test('swiss national days move with easter and include the federal day', function (): void {
    $dates = ($this->dates)($this->factory->holidays('CH', 2026));

    expect($dates)->toContain('2026-01-01', '2026-04-03', '2026-04-06', '2026-05-14', '2026-05-25', '2026-08-01', '2026-12-25', '2026-12-26')
        ->and($dates)->not->toContain('2026-01-02');
});

test('cantons add their own days on top of the swiss ones', function (): void {
    $zurich = ($this->dates)($this->factory->holidays('CH', 2026, 'ZH'));
    $ticino = ($this->dates)($this->factory->holidays('CH', 2026, 'ti'));
    $geneva = $this->factory->holidays('CH', 2026, 'GE');

    expect($zurich)->toContain('2026-01-02', '2026-05-01')
        ->and($ticino)->toContain('2026-01-06', '2026-03-19', '2026-06-04', '2026-06-29', '2026-08-15', '2026-12-08')
        ->and(($this->dates)($geneva))->toContain('2026-09-10', '2026-12-31');

    $fast = collect($geneva)->firstWhere('date', '2026-09-10');
    expect($fast['type'])->toBe('cantonal')->and($fast['region'])->toBe('GE');
});

test('italian national days include the liberation day and the optional patron saint', function (): void {
    $national = ($this->dates)($this->factory->holidays('IT', 2026));
    $withSaint = $this->factory->holidays('IT', 2026, null, 'roma');
    $custom = ($this->dates)($this->factory->holidays('IT', 2026, null, '10-04'));

    expect($national)->toContain('2026-01-06', '2026-04-05', '2026-04-06', '2026-04-25', '2026-06-02', '2026-08-15', '2026-12-08')
        ->and($national)->not->toContain('2026-06-29')
        ->and(collect($withSaint)->firstWhere('date', '2026-06-29')['type'])->toBe('patron_saint')
        ->and($custom)->toContain('2026-10-04');
});

test('a patron saint on a national day is listed once', function (): void {
    $holidays = $this->factory->holidays('IT', 2026, null, 'venezia');

    expect(array_count_values(array_column($holidays, 'date'))['2026-04-25'])->toBe(1);
});

test('holidays come sorted by date and an unsupported country is refused', function (): void {
    $dates = ($this->dates)($this->factory->holidays('CH', 2027, 'VD'));
    $sorted = $dates;
    sort($sorted);

    expect($dates)->toBe($sorted);

    expect(fn () => $this->factory->holidays('FR', 2026))->toThrow(InvalidArgumentException::class);
});
