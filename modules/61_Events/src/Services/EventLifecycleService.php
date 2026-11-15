<?php

declare(strict_types=1);

namespace Modules\Events\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\EventTicketType;
use Modules\Events\Models\ManagedEvent;

/**
 * Create, change and move events through draft, published, closed and cancelled.
 */
final readonly class EventLifecycleService
{
    public function __construct(private EventRegistrationService $registrations) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): ManagedEvent
    {
        $this->assertCodeFree($tenantId, (string) $data['code'], null);

        return ManagedEvent::create([...$data, 'tenant_id' => $tenantId, 'status' => ManagedEvent::Draft])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ManagedEvent $event, array $data): ManagedEvent
    {
        return DB::transaction(function () use ($event, $data): ManagedEvent {
            if (isset($data['code'])) {
                $this->assertCodeFree((string) $event->tenant_id, (string) $data['code'], (string) $event->id);
            }

            $event->update($data);

            if ($event->ends_at->lt($event->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => [__('event_errors.event.period')]]);
            }

            if ($event->capacity !== null && EventRegistration::query()->where('event_id', $event->id)->where('status', EventRegistration::Confirmed)->count() > $event->capacity) {
                throw ValidationException::withMessages(['capacity' => [__('event_errors.event.capacity_below_confirmed')]]);
            }

            $this->registrations->promoteWaitlist((string) $event->id);

            return $event->refresh();
        });
    }

    public function publish(ManagedEvent $event): ManagedEvent
    {
        if ($event->status !== ManagedEvent::Draft) {
            throw ValidationException::withMessages(['event' => [__('event_errors.event.not_draft')]]);
        }

        if (! EventTicketType::query()->where('event_id', $event->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['event' => [__('event_errors.event.ticket_required')]]);
        }

        $event->update(['status' => ManagedEvent::Published]);

        return $event->refresh();
    }

    public function close(ManagedEvent $event): ManagedEvent
    {
        if ($event->status !== ManagedEvent::Published) {
            throw ValidationException::withMessages(['event' => [__('event_errors.event.not_published')]]);
        }

        $event->update(['status' => ManagedEvent::Closed]);

        return $event->refresh();
    }

    public function cancel(ManagedEvent $event): ManagedEvent
    {
        if (! in_array($event->status, [ManagedEvent::Draft, ManagedEvent::Published], true)) {
            throw ValidationException::withMessages(['event' => [__('event_errors.event.cannot_cancel')]]);
        }

        DB::transaction(function () use ($event): void {
            $event->update(['status' => ManagedEvent::Cancelled]);
            EventRegistration::query()->where('event_id', $event->id)->whereIn('status', [EventRegistration::Confirmed, EventRegistration::Waitlisted])->whereNull('checked_in_at')->update(['status' => EventRegistration::Cancelled, 'cancelled_at' => now()]);
        });

        return $event->refresh();
    }

    public function delete(ManagedEvent $event): void
    {
        if ($event->status !== ManagedEvent::Draft || EventRegistration::query()->where('event_id', $event->id)->exists()) {
            throw ValidationException::withMessages(['event' => [__('event_errors.event.delete_draft_only')]]);
        }

        DB::transaction(function () use ($event): void {
            $event->sessions()->delete();
            $event->ticketTypes()->delete();
            $event->delete();
        });
    }

    private function assertCodeFree(string $tenantId, string $code, ?string $ignoreId): void
    {
        $taken = ManagedEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('event_errors.event.code_taken', ['code' => $code])]]);
        }
    }
}
