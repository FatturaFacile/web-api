<?php

declare(strict_types=1);

namespace Modules\Hospitality\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Hospitality\Models\DiningTable;
use Modules\Hospitality\Models\Order;
use Modules\Hospitality\Models\Reservation;

/**
 * Table reservations: a booking holds a table for an interval, two bookings never overlap on the same table, and
 * seating the guests opens the order of the table. Instants are stored in UTC; the calendar day of a venue is the one
 * on its own wall clock (TenantClock).
 */
final class ReservationService
{
    public function __construct(
        private readonly HospitalityService $service,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Books a table, or leaves the table open for the venue to assign later. The party must fit the table and the
     * interval must be free on it.
     *
     * @param  array{table_id?: ?string, contact_id?: ?string, guest_name: string, guest_phone?: ?string, guest_email?: ?string, party_size: int, starts_at: string, duration_minutes: int, notes?: ?string}  $data
     */
    public function book(array $data, ?string $userId): Reservation
    {
        return DB::transaction(function () use ($data, $userId): Reservation {
            $start = CarbonImmutable::parse($data['starts_at'])->utc();
            $end = $start->addMinutes((int) $data['duration_minutes']);

            if (($data['table_id'] ?? null) !== null) {
                $this->assertTableFree($this->lockTable((string) $data['table_id']), (int) $data['party_size'], $start, $end, null);
            }

            $reservation = new Reservation;
            $reservation->fill([
                'table_id' => $data['table_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'guest_name' => $data['guest_name'],
                'guest_phone' => $data['guest_phone'] ?? null,
                'guest_email' => $data['guest_email'] ?? null,
                'party_size' => $data['party_size'],
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => 'booked',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $reservation->save();

            return $reservation;
        });
    }

    /**
     * Moves a booked reservation to another time, party or table, with the same checks as a new booking.
     *
     * @param  array{table_id?: ?string, guest_name?: string, guest_phone?: ?string, guest_email?: ?string, party_size?: int, starts_at?: string, duration_minutes?: int, notes?: ?string}  $data
     */
    public function reschedule(Reservation $reservation, array $data): Reservation
    {
        return DB::transaction(function () use ($reservation, $data): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== 'booked') {
                throw new BusinessRuleException('Only a booked reservation can change.');
            }

            $start = isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at'])->utc() : $locked->starts_at;
            $minutes = isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : (int) $locked->starts_at->diffInMinutes($locked->ends_at);
            $end = $start->addMinutes($minutes);
            $tableId = array_key_exists('table_id', $data) ? $data['table_id'] : $locked->table_id;
            $party = (int) ($data['party_size'] ?? $locked->party_size);

            if ($tableId !== null) {
                $this->assertTableFree($this->lockTable((string) $tableId), $party, $start, $end, $locked->id);
            }

            $locked->fill([
                ...array_intersect_key($data, array_flip(['guest_name', 'guest_phone', 'guest_email', 'notes'])),
                'table_id' => $tableId,
                'party_size' => $party,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Cancels, marks no-show or completes a reservation along Reservation::Transitions. Cancelling and no-show take a
     * reason so the venue can see why a table went empty.
     */
    public function transition(Reservation $reservation, string $target, ?string $reason): Reservation
    {
        return DB::transaction(function () use ($reservation, $target, $reason): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertTransition($locked, $target);
            $locked->update(['status' => $target, 'status_reason' => $reason ?? $locked->status_reason]);

            return $locked;
        });
    }

    /**
     * Seats the guests: the order of the table is opened with the party size as covers. A table can be named now when
     * the reservation had none, and a walk-in swap to another free table is allowed.
     */
    public function seat(Reservation $reservation, ?string $tableId, ?string $userId): Reservation
    {
        return DB::transaction(function () use ($reservation, $tableId, $userId): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertTransition($locked, 'seated');
            $targetId = $tableId ?? $locked->table_id;

            if ($targetId === null) {
                throw new BusinessRuleException('Assign a table before seating the guests.');
            }

            $table = $this->lockTable((string) $targetId);
            $this->assertTableFree($table, (int) $locked->party_size, $locked->starts_at, $locked->ends_at, $locked->id);
            $order = $this->service->openOrder($table, (int) $locked->party_size, $userId, $locked->notes);
            $locked->update(['table_id' => $table->id, 'order_id' => $order->id, 'status' => 'seated']);

            return $locked;
        });
    }

    /**
     * The reservations of one calendar day of the venue, in time order.
     *
     * @return Collection<int, Reservation>
     */
    public function calendar(string $date, ?string $roomId, ?string $status): Collection
    {
        return $this->calendarQuery($date, $roomId, $status)->get();
    }

    /**
     * The query behind {@see self::calendar()}, for grids that page, sort and filter it on the server.
     *
     * @return Builder<Reservation>
     */
    public function calendarQuery(string $date, ?string $roomId, ?string $status): Builder
    {
        [$from, $to] = $this->dayRange($date);

        return Reservation::query()
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($roomId !== null, fn ($query) => $query->whereIn('table_id', DiningTable::query()->where('room_id', $roomId)->select('id')))
            ->with('diningTable')
            ->orderBy('starts_at');
    }

    /**
     * The active tables that seat the party and are free for the whole interval. When the interval includes the present
     * moment a table with an open order is not free.
     *
     * @return Collection<int, DiningTable>
     */
    public function availableTables(string $startsAt, int $durationMinutes, int $partySize): Collection
    {
        $start = CarbonImmutable::parse($startsAt)->utc();
        $end = $start->addMinutes($durationMinutes);
        $held = Reservation::query()->whereIn('status', Reservation::Holding)->whereNotNull('table_id')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->pluck('table_id');
        $now = CarbonImmutable::now()->utc();
        $occupied = $start <= $now && $now < $end
            ? Order::query()->where('status', 'open')->pluck('table_id')
            : collect();

        return DiningTable::query()->where('is_active', true)->where('seats', '>=', $partySize)
            ->whereNotIn('id', $held->merge($occupied)->unique()->all())
            ->orderBy('seats')->orderBy('name')->get();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} the start and end of the venue's day, as UTC instants
     */
    private function dayRange(string $date): array
    {
        $start = CarbonImmutable::parse($date, $this->clock->timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    private function lockTable(string $tableId): DiningTable
    {
        return DiningTable::query()->lockForUpdate()->findOrFail($tableId);
    }

    private function assertTransition(Reservation $reservation, string $target): void
    {
        if (! in_array($target, Reservation::Transitions[$reservation->status] ?? [], true)) {
            throw new BusinessRuleException("A reservation cannot move from {$reservation->status} to {$target}.");
        }
    }

    private function assertTableFree(DiningTable $table, int $partySize, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreId): void
    {
        if (! $table->is_active) {
            throw new BusinessRuleException('The table is not in service.');
        }

        if ($partySize > (int) $table->seats) {
            throw new BusinessRuleException("The table seats {$table->seats}: the party of {$partySize} does not fit.");
        }

        $overlaps = Reservation::query()->where('table_id', $table->id)->whereIn('status', Reservation::Holding)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))->exists();

        if ($overlaps) {
            throw new BusinessRuleException('The table is already reserved for part of this interval.');
        }
    }
}
