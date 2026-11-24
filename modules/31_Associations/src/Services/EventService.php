<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Enums\CourseKind;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\EventRegistration;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\TicketType;
use Modules\Associations\Support\Money;

/**
 * Registrations and tickets of the events. The places of an event are limited by its ticket type and by the capacity of
 * the event itself; the event row is locked for every change.
 */
final class EventService
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly FeeService $fees,
    ) {}

    public function register(TicketType $ticketType, Member $member): EventRegistration
    {
        return DB::transaction(function () use ($ticketType, $member): EventRegistration {
            /** @var Course $event */
            $event = Course::query()->whereKey($ticketType->course_id)->lockForUpdate()->firstOrFail();

            if ($event->kind === CourseKind::Recurring || $event->status !== Course::Active || ! $ticketType->is_active) {
                throw AssociationException::conflict(__('associations.course_not_active'));
            }

            $this->certificates->assertCovered($member, $event->discipline_id, $event->required_certificate);

            $isFull = $this->isFull($event, $ticketType);

            if ($isFull && ! $event->waitlist_enabled) {
                throw AssociationException::conflict(__('associations.course_full'));
            }

            $registration = EventRegistration::create([
                'course_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'member_id' => $member->id,
                'status' => $isFull ? EventRegistration::Waitlisted : EventRegistration::Confirmed,
                'waitlist_position' => $isFull ? $this->nextPosition($event) : null,
                'ticket_code' => Str::upper(Str::random(20)),
            ]);

            if (! $isFull) {
                $this->charge($registration, $ticketType, $event, $member);
            }

            return $registration;
        });
    }

    public function cancel(EventRegistration $registration): EventRegistration
    {
        return DB::transaction(function () use ($registration): EventRegistration {
            /** @var Course $event */
            $event = Course::query()->whereKey($registration->course_id)->lockForUpdate()->firstOrFail();
            $hadPlace = $registration->status === EventRegistration::Confirmed;

            $registration->update(['status' => EventRegistration::Cancelled, 'waitlist_position' => null]);

            if ($hadPlace) {
                $this->promote($event);
            }

            return $registration;
        });
    }

    public function checkIn(Course $event, string $ticketCode): EventRegistration
    {
        return DB::transaction(function () use ($event, $ticketCode): EventRegistration {
            /** @var EventRegistration $registration */
            $registration = EventRegistration::query()->where('course_id', $event->id)->where('ticket_code', $ticketCode)
                ->lockForUpdate()->firstOrFail();

            if ($registration->status !== EventRegistration::Confirmed) {
                throw AssociationException::conflict(__('associations.ticket_not_confirmed'));
            }

            if ($registration->checked_in_at !== null) {
                throw AssociationException::conflict(__('associations.ticket_already_used'));
            }

            $registration->update(['checked_in_at' => now()]);

            return $registration;
        });
    }

    private function promote(Course $event): void
    {
        $waiting = EventRegistration::query()->with(['ticketType', 'member'])->where('course_id', $event->id)
            ->where('status', EventRegistration::Waitlisted)->orderBy('waitlist_position')->get();

        foreach ($waiting as $registration) {
            if (! $this->isFull($event, $registration->ticketType)) {
                $registration->update(['status' => EventRegistration::Confirmed, 'waitlist_position' => null]);
                $this->charge($registration, $registration->ticketType, $event, $registration->member);

                return;
            }
        }
    }

    private function isFull(Course $event, TicketType $ticketType): bool
    {
        $confirmed = EventRegistration::query()->where('course_id', $event->id)->where('status', EventRegistration::Confirmed);
        $ofType = (clone $confirmed)->where('ticket_type_id', $ticketType->id)->count();

        return ($ticketType->quantity !== null && $ofType >= $ticketType->quantity)
            || ($event->capacity !== null && $confirmed->count() >= $event->capacity);
    }

    private function nextPosition(Course $event): int
    {
        return (int) EventRegistration::query()->where('course_id', $event->id)->max('waitlist_position') + 1;
    }

    private function charge(EventRegistration $registration, TicketType $ticketType, Course $event, Member $member): void
    {
        $price = Money::normalize((string) $ticketType->price);

        if (Money::isPositive($price)) {
            $assignment = $this->fees->charge($member, "{$event->title} - {$ticketType->name}", $price, CarbonImmutable::today(), $event->id);
            $registration->update(['fee_assignment_id' => $assignment->id]);
        }
    }
}
