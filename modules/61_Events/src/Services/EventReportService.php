<?php

declare(strict_types=1);

namespace Modules\Events\Services;

use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\ManagedEvent;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Revenue and attendance of an event: per ticket type and in total, expected (confirmed registrations) against
 * collected (paid ones), the waiting list, cancellations, check-ins and no-shows.
 */
final class EventReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(ManagedEvent $event): array
    {
        $registrations = EventRegistration::query()->where('event_id', $event->id)->get();
        $confirmed = $registrations->where('status', EventRegistration::Confirmed);
        $rows = [];

        foreach ($event->ticketTypes()->get() as $ticket) {
            $sold = $confirmed->where('ticket_type_id', $ticket->id);
            $rows[] = [
                'ticket_type_id' => $ticket->id,
                'name' => $ticket->name,
                'price' => $ticket->price,
                'quantity' => $ticket->quantity,
                'sold' => $sold->count(),
                'waitlisted' => $registrations->where('status', EventRegistration::Waitlisted)->where('ticket_type_id', $ticket->id)->count(),
                'expected_revenue' => $this->sum($sold),
                'collected_revenue' => $this->sum($sold->whereNotNull('paid_at')),
            ];
        }

        $expected = $this->sum($confirmed);
        $collected = $this->sum($confirmed->whereNotNull('paid_at'));

        return [
            'event_id' => $event->id,
            'currency' => $event->currency,
            'capacity' => $event->capacity,
            'confirmed' => $confirmed->count(),
            'waitlisted' => $registrations->where('status', EventRegistration::Waitlisted)->count(),
            'cancelled' => $registrations->where('status', EventRegistration::Cancelled)->count(),
            'checked_in' => $confirmed->whereNotNull('checked_in_at')->count(),
            'no_show' => $confirmed->whereNull('checked_in_at')->count(),
            'occupancy_percent' => $event->capacity ? number_format($confirmed->count() * 100 / $event->capacity, 2, '.', '') : null,
            'expected_revenue' => $expected,
            'collected_revenue' => $collected,
            'outstanding_revenue' => Decimal::round2(Decimal::sub($expected, $collected)),
            'by_ticket_type' => $rows,
        ];
    }

    /**
     * @param  iterable<EventRegistration>  $registrations
     */
    private function sum(iterable $registrations): string
    {
        $total = '0';

        foreach ($registrations as $registration) {
            $total = Decimal::add($total, (string) $registration->price);
        }

        return Decimal::round2($total);
    }
}
