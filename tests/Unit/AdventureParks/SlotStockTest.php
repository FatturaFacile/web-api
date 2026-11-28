<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\AdventureParks\Domain\ValueObjects\SlotWindow;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Models\SlotTemplate;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Models\TicketLine;
use Modules\AdventureParks\Services\SlotAvailabilityService;

/**
 * @param  array<string, mixed>  $overrides
 */
function advStockAsset(string $size, array $overrides = []): PpeAsset
{
    return new PpeAsset(array_merge([
        'serial_code' => $size.'-'.random_int(1000, 999999),
        'category' => 'HARNESS',
        'size' => $size,
        'status' => 'AVAILABLE',
        'manufacturer_max_lifespan_date' => '2030-12-31',
        'next_inspection_due_date' => '2027-01-01',
    ], $overrides));
}

/**
 * @param  array<string, int>  $quantities
 */
function advStockTicket(string $start, string $end, array $quantities, string $status = 'BOOKED'): Ticket
{
    $ticket = new Ticket(['slot_date' => '2026-06-16', 'start_time' => $start, 'end_time' => $end, 'status' => $status, 'headcount' => array_sum($quantities)]);
    $ticket->setRelation('lines', collect(array_map(
        static fn (string $size, int $quantity): TicketLine => new TicketLine(['size' => $size, 'quantity' => $quantity]),
        array_keys($quantities),
        array_values($quantities),
    )));

    return $ticket;
}

describe('slot window', function (): void {
    it('measures minutes and the duration of a slot', function (): void {
        $window = SlotWindow::fromTimes('10:00', '12:30');

        expect($window->startMinutes)->toBe(600)->and($window->endMinutes)->toBe(750)->and($window->durationMinutes())->toBe(150)
            ->and(SlotWindow::toMinutes('09:05'))->toBe(545);
    });

    it('refuses a slot that does not end after it starts', function (): void {
        SlotWindow::fromTimes('12:00', '12:00');
    })->throws(InvalidArgumentException::class);

    it('overlaps with the others inside [start - buffer, end + buffer]', function (string $start, string $end, bool $overlaps): void {
        $slot = SlotWindow::fromTimes('10:00', '12:00');

        expect($slot->overlapsWithBuffer(SlotWindow::fromTimes($start, $end), 15))->toBe($overlaps);
    })->with([
        'the same slot' => ['10:00', '12:00', true],
        'inside' => ['10:30', '11:00', true],
        'starts in the buffer after the end' => ['12:10', '14:00', true],
        'starts exactly when the buffer ends' => ['12:15', '14:00', false],
        'ends in the buffer before the start' => ['08:00', '10:10', true],
        'ends exactly when the buffer starts' => ['08:00', '09:45', false],
        'far away' => ['14:00', '16:00', false],
    ]);
});

describe('stock per size', function (): void {
    it('is the usable harnesses of the size minus the harnesses held by the overlapping active tickets', function (): void {
        $service = app(SlotAvailabilityService::class);
        $day = CarbonImmutable::parse('2026-06-16');
        $slot = new SlotTemplate(['start_time' => '10:00', 'end_time' => '12:00']);

        $assets = collect([
            advStockAsset('JUNIOR'), advStockAsset('JUNIOR'), advStockAsset('JUNIOR'), advStockAsset('JUNIOR', ['status' => 'IN_USE']),
            advStockAsset('JUNIOR', ['status' => 'MAINTENANCE_REQUIRED']),
            advStockAsset('JUNIOR', ['status' => 'EXPIRED_BLOCKED']),
            advStockAsset('JUNIOR', ['manufacturer_max_lifespan_date' => '2026-06-10']),
            advStockAsset('JUNIOR', ['next_inspection_due_date' => '2026-06-01']),
            advStockAsset('ADULT'), advStockAsset('ADULT'),
        ]);

        $tickets = collect([
            advStockTicket('10:00', '12:00', ['JUNIOR' => 2]),
            advStockTicket('12:10', '14:00', ['JUNIOR' => 1, 'ADULT' => 1]),
            advStockTicket('14:00', '16:00', ['JUNIOR' => 3]),
        ]);

        $stock = $service->stockFor($slot, $day, $assets, $tickets);

        expect($stock['JUNIOR'])->toBe(['total' => 4, 'booked' => 3, 'remaining' => 1])
            ->and($stock['ADULT'])->toBe(['total' => 2, 'booked' => 1, 'remaining' => 1])
            ->and($stock['BABY'])->toBe(['total' => 0, 'booked' => 0, 'remaining' => 0])
            ->and($stock['XL']['remaining'])->toBe(0);
    });

    it('never goes below zero when a size is over-booked', function (): void {
        $service = app(SlotAvailabilityService::class);
        $stock = $service->stockFor(
            new SlotTemplate(['start_time' => '10:00', 'end_time' => '12:00']),
            CarbonImmutable::parse('2026-06-16'),
            collect([advStockAsset('XL')]),
            collect([advStockTicket('10:00', '12:00', ['XL' => 3])]),
        );

        expect($stock['XL'])->toBe(['total' => 1, 'booked' => 3, 'remaining' => 0]);
    });

    it('judges the harnesses on the day of the slot, not on today', function (): void {
        $service = app(SlotAvailabilityService::class);
        $slot = new SlotTemplate(['start_time' => '10:00', 'end_time' => '12:00']);
        $assets = collect([advStockAsset('ADULT', ['manufacturer_max_lifespan_date' => '2026-06-20'])]);

        expect($service->stockFor($slot, CarbonImmutable::parse('2026-06-16'), $assets, collect())['ADULT']['total'])->toBe(1)
            ->and($service->stockFor($slot, CarbonImmutable::parse('2026-06-21'), $assets, collect())['ADULT']['total'])->toBe(0);
    });
});

describe('slot template', function (): void {
    it('runs on the weekdays it lists, every day when none is listed, and not when it is inactive', function (): void {
        $monday = CarbonImmutable::parse('2026-06-15');
        $tuesday = CarbonImmutable::parse('2026-06-16');

        $mondays = new SlotTemplate(['start_time' => '10:00', 'end_time' => '12:00', 'weekdays' => [1], 'is_active' => true]);
        $always = new SlotTemplate(['start_time' => '10:00', 'end_time' => '12:00', 'is_active' => true]);
        $off = new SlotTemplate(['start_time' => '10:00', 'end_time' => '12:00', 'is_active' => false]);

        expect($mondays->isOpenOn($monday))->toBeTrue()
            ->and($mondays->isOpenOn($tuesday))->toBeFalse()
            ->and($always->isOpenOn($tuesday))->toBeTrue()
            ->and($off->isOpenOn($monday))->toBeFalse();
    });
});
