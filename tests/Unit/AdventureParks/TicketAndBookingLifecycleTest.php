<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\AdventureParks\Domain\Enums\TicketStatus;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\SchoolBooking;
use Modules\AdventureParks\Models\Ticket;

/**
 * @param  array<string, mixed>  $overrides
 */
function advTicket(array $overrides = []): Ticket
{
    return new Ticket(array_merge(['booking_code' => 'PK-260616-ABCDE', 'start_time' => '10:00', 'end_time' => '12:00', 'headcount' => 2, 'status' => 'BOOKED'], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function advSchoolBooking(array $overrides = []): SchoolBooking
{
    return new SchoolBooking(array_merge([
        'institute_name' => 'IC Pascoli', 'ipa_office_code' => 'UFABCD', 'visit_date' => '2026-09-24', 'status' => 'DRAFT',
        'paying_students' => 30, 'disabled_students' => 0, 'accompanying_teachers' => 2,
    ], $overrides));
}

describe('ticket state machine', function (): void {
    it('walks the happy path', function (): void {
        $ticket = advTicket();

        $ticket->markWaiverSigned(2);
        expect($ticket->status)->toBe('WAIVER_SIGNED');

        $ticket->equip();
        expect($ticket->status)->toBe('EQUIPPED');

        $ticket->startCourse();
        expect($ticket->status)->toBe('ON_COURSE');

        $ticket->markReturned();
        expect($ticket->status)->toBe('RETURNED')->and($ticket->holdsStock())->toBeFalse();
    });

    it('allows a no-show only before the group is equipped and frees its stock', function (): void {
        $booked = advTicket();
        $booked->markNoShow();

        $signed = advTicket(['status' => 'WAIVER_SIGNED']);
        $signed->markNoShow();

        expect($booked->status)->toBe('NO_SHOW')->and($booked->holdsStock())->toBeFalse()->and($signed->status)->toBe('NO_SHOW');

        $equipped = advTicket(['status' => 'EQUIPPED']);
        expect($equipped->canTransitionTo(TicketStatus::NoShow))->toBeFalse();
    });

    it('refuses the transitions that skip a step or leave a final state', function (string $from, string $to): void {
        $ticket = advTicket(['status' => $from]);

        try {
            $ticket->transitionTo(TicketStatus::from($to));
            $this->fail('The transition must be refused.');
        } catch (AdventureParksException $exception) {
            expect($exception->errorCode())->toBe('invalid_transition')->and($exception->context())->toBe(['from' => $from, 'to' => $to]);
        }

        expect($ticket->status)->toBe($from);
    })->with([
        'booked to equipped' => ['BOOKED', 'EQUIPPED'],
        'booked to on course' => ['BOOKED', 'ON_COURSE'],
        'waiver signed to on course' => ['WAIVER_SIGNED', 'ON_COURSE'],
        'on course to no-show' => ['ON_COURSE', 'NO_SHOW'],
        'returned to booked' => ['RETURNED', 'BOOKED'],
        'no-show to waiver signed' => ['NO_SHOW', 'WAIVER_SIGNED'],
    ]);

    it('needs a waiver that covers exactly the headcount of the booking', function (): void {
        $ticket = advTicket(['headcount' => 3]);

        try {
            $ticket->markWaiverSigned(2);
            $this->fail('The waiver must cover the whole group.');
        } catch (AdventureParksException $exception) {
            expect($exception->errorCode())->toBe('headcount_mismatch')->and($exception->context())->toBe(['expected' => 3, 'given' => 2]);
        }

        expect($ticket->status)->toBe('BOOKED');
    });

    it('hands out equipment only after a signed waiver that covers every participant', function (): void {
        $booked = advTicket();
        $signed = advTicket(['status' => 'WAIVER_SIGNED']);

        expect(fn () => $booked->assertWaiverCovers(2))->toThrow(AdventureParksException::class)
            ->and(fn () => $signed->assertWaiverCovers(1))->toThrow(AdventureParksException::class);

        $signed->assertWaiverCovers(2);
        expect($signed->status)->toBe('WAIVER_SIGNED');
    });
});

describe('school booking aggregate', function (): void {
    it('walks draft, confirmed, completed and invoiced', function (): void {
        $booking = advSchoolBooking();
        $booking->confirm();
        $booking->complete();
        $booking->markInvoiced('0198a2d0-0000-7000-8000-000000000001', 'FT-1', CarbonImmutable::parse('2026-09-25 10:00:00'));

        expect($booking->status)->toBe('INVOICED')->and($booking->fattura_number)->toBe('FT-1')->and($booking->invoiced_at->toDateString())->toBe('2026-09-25');
    });

    it('refuses to invoice a draft and to invoice twice, and locks an invoiced booking', function (): void {
        $draft = advSchoolBooking();
        $invoiced = advSchoolBooking(['status' => 'INVOICED', 'fattura_invoice_id' => '0198a2d0-0000-7000-8000-000000000001']);

        foreach ([[$draft, 'booking_not_confirmed'], [$invoiced, 'already_invoiced']] as [$booking, $code]) {
            try {
                $booking->assertInvoiceable();
                $this->fail('The booking must not be invoiceable.');
            } catch (AdventureParksException $exception) {
                expect($exception->errorCode())->toBe($code);
            }
        }

        expect(fn () => $invoiced->assertEditable())->toThrow(AdventureParksException::class);
        $draft->assertEditable();
        $draft->confirm();
        $draft->assertInvoiceable();
        expect($draft->status)->toBe('CONFIRMED');
    });

    it('confirms only a draft and completes only a confirmed booking', function (): void {
        expect(fn () => advSchoolBooking(['status' => 'CONFIRMED'])->confirm())->toThrow(AdventureParksException::class)
            ->and(fn () => advSchoolBooking()->complete())->toThrow(AdventureParksException::class);
    });

    it('stores the quote on the booking', function (): void {
        $booking = advSchoolBooking();
        $booking->applyQuote(['free_teachers' => 2, 'charged_teachers' => 0, 'picnic_seats' => 32, 'total_amount' => '240.00']);

        expect($booking->free_teachers)->toBe(2)->and($booking->picnic_seats)->toBe(32)->and($booking->total_amount)->toBe('240.00');
    });
});
