<?php

declare(strict_types=1);

namespace Modules\Events\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\EventTicketType;
use Modules\Events\Models\ManagedEvent;

/**
 * Registrations of an event: places are held by confirmed registrations, the event capacity and the quantity of the
 * ticket type both apply, and a full event sends new registrations to the waiting list (or refuses them when the
 * waiting list is off). A freed place goes to the oldest waiting registration that fits. Check-in is by QR token.
 */
final class EventRegistrationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function register(string $tenantId, ManagedEvent $event, array $data): EventRegistration
    {
        return DB::transaction(function () use ($tenantId, $event, $data): EventRegistration {
            $locked = ManagedEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ManagedEvent::Published) {
                throw ValidationException::withMessages(['event' => [__('event_errors.event.not_published')]]);
            }

            $ticket = EventTicketType::query()->where('event_id', $locked->id)->whereKey($data['ticket_type_id'])->first();

            if ($ticket === null || ! $ticket->is_active) {
                throw ValidationException::withMessages(['ticket_type_id' => [__('event_errors.ticket.unavailable')]]);
            }

            $fits = $this->hasRoom($locked, $ticket);

            if (! $fits && ! $locked->waitlist_enabled) {
                throw ValidationException::withMessages(['event' => [__('event_errors.event.full')]]);
            }

            return EventRegistration::create([
                'tenant_id' => $tenantId,
                'event_id' => $locked->id,
                'ticket_type_id' => $ticket->id,
                'contact_id' => $data['contact_id'] ?? null,
                'attendee_name' => $data['attendee_name'],
                'attendee_email' => $data['attendee_email'] ?? null,
                'status' => $fits ? EventRegistration::Confirmed : EventRegistration::Waitlisted,
                'qr_token' => Str::random(40),
                'price' => $ticket->price,
                'registered_at' => now(),
                'notes' => $data['notes'] ?? null,
            ])->refresh();
        });
    }

    public function cancel(EventRegistration $registration): EventRegistration
    {
        return DB::transaction(function () use ($registration): EventRegistration {
            ManagedEvent::query()->whereKey($registration->event_id)->lockForUpdate()->firstOrFail();
            $current = EventRegistration::query()->findOrFail($registration->id);

            if ($current->status === EventRegistration::Cancelled) {
                throw ValidationException::withMessages(['registration' => [__('event_errors.registration.already_cancelled')]]);
            }

            if ($current->checked_in_at !== null) {
                throw ValidationException::withMessages(['registration' => [__('event_errors.registration.checked_in')]]);
            }

            $heldPlace = $current->status === EventRegistration::Confirmed;
            $current->update(['status' => EventRegistration::Cancelled, 'cancelled_at' => now()]);

            if ($heldPlace) {
                $this->promoteWaitlist((string) $current->event_id);
            }

            return $current->refresh();
        });
    }

    /**
     * Moves the oldest waiting registrations that fit to confirmed. Returns how many were promoted.
     */
    public function promoteWaitlist(string $eventId): int
    {
        $event = ManagedEvent::query()->findOrFail($eventId);

        if ($event->status !== ManagedEvent::Published) {
            return 0;
        }

        $promoted = 0;

        foreach (EventRegistration::query()->where('event_id', $eventId)->where('status', EventRegistration::Waitlisted)->orderBy('registered_at')->orderBy('id')->get() as $waiting) {
            $ticket = EventTicketType::query()->findOrFail($waiting->ticket_type_id);

            if ($this->hasRoom($event, $ticket)) {
                $waiting->update(['status' => EventRegistration::Confirmed]);
                $promoted++;
            }
        }

        return $promoted;
    }

    public function markPaid(EventRegistration $registration): EventRegistration
    {
        if ($registration->status !== EventRegistration::Confirmed) {
            throw ValidationException::withMessages(['registration' => [__('event_errors.registration.not_confirmed')]]);
        }

        $registration->update(['paid_at' => $registration->paid_at ?? now()]);

        return $registration->refresh();
    }

    public function checkIn(string $tenantId, string $qrToken, ?string $eventId, ?string $userId): EventRegistration
    {
        return DB::transaction(function () use ($tenantId, $qrToken, $eventId, $userId): EventRegistration {
            $registration = EventRegistration::query()
                ->where('tenant_id', $tenantId)
                ->where('qr_token', $qrToken)
                ->when($eventId !== null, static fn ($query) => $query->where('event_id', $eventId))
                ->lockForUpdate()
                ->first();

            if ($registration === null) {
                throw ValidationException::withMessages(['qr_token' => [__('event_errors.checkin.unknown_token')]]);
            }

            if ($registration->status !== EventRegistration::Confirmed) {
                throw ValidationException::withMessages(['qr_token' => [__('event_errors.checkin.not_confirmed')]]);
            }

            if ($registration->checked_in_at !== null) {
                throw ValidationException::withMessages(['qr_token' => [__('event_errors.checkin.already', ['at' => $registration->checked_in_at->toDateTimeString()])]]);
            }

            $registration->update(['checked_in_at' => Carbon::now(), 'checked_in_by' => $userId]);

            return $registration->refresh();
        });
    }

    private function hasRoom(ManagedEvent $event, EventTicketType $ticket): bool
    {
        $held = static fn () => EventRegistration::query()->where('event_id', $event->id)->where('status', EventRegistration::Confirmed);

        if ($event->capacity !== null && $held()->count() >= $event->capacity) {
            return false;
        }

        return $ticket->quantity === null || $held()->where('ticket_type_id', $ticket->id)->count() < $ticket->quantity;
    }
}
