<?php

declare(strict_types=1);

namespace Modules\Facility\Services;

use App\Services\Tenancy\TenantClock;
use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Facility\Models\FacilityBooking;
use Modules\Facility\Models\FacilityBookingRule;
use Modules\Facility\Models\FacilityBookingSeries;
use Modules\Facility\Models\FacilitySpace;

/**
 * Room and desk booking: conflict detection on overlapping intervals, per-kind rules (duration, advance, daily limit),
 * recurring series, check-in and the release of bookings nobody turned up for.
 *
 * Intervals are half-open: a booking that ends at 10:00 does not conflict with one that starts at 10:00. Days are the
 * business days of the company time zone. A booking is only taken after the space is locked for the transaction, so two
 * concurrent requests for the same slot cannot both succeed.
 */
final readonly class BookingService
{
    /** Minutes in the past a booking may still start (a "book it now" request that took a moment). */
    private const int PastToleranceMinutes = 5;

    public function __construct(private TenantClock $clock, private FacilityLedger $ledger) {}

    /**
     * The limits that apply to a kind of space: the company rule over the configured default.
     *
     * @return array{max_bookings_per_user_per_day: int|null, max_minutes_per_booking: int|null, max_advance_days: int|null, checkin_grace_minutes: int, requires_checkin: bool}
     */
    public function rulesFor(string $kind): array
    {
        $defaults = (array) config('facility.booking_defaults');
        $rules = array_merge(Arr::except($defaults, ['kinds']), (array) ($defaults['kinds'][$kind] ?? []));
        $rule = FacilityBookingRule::query()->where('space_kind', $kind)->first();

        if ($rule !== null) {
            foreach (array_keys($rules) as $field) {
                $rules[$field] = $rule->{$field};
            }
        }

        return [
            'max_bookings_per_user_per_day' => $rules['max_bookings_per_user_per_day'] === null ? null : (int) $rules['max_bookings_per_user_per_day'],
            'max_minutes_per_booking' => $rules['max_minutes_per_booking'] === null ? null : (int) $rules['max_minutes_per_booking'],
            'max_advance_days' => $rules['max_advance_days'] === null ? null : (int) $rules['max_advance_days'],
            'checkin_grace_minutes' => (int) $rules['checkin_grace_minutes'],
            'requires_checkin' => (bool) $rules['requires_checkin'],
        ];
    }

    /**
     * Creates or replaces the rule of a kind of space.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveRule(string $kind, array $data): FacilityBookingRule
    {
        $rule = FacilityBookingRule::query()->firstOrNew(['space_kind' => $kind]);
        $rule->fill([
            'max_bookings_per_user_per_day' => $data['max_bookings_per_user_per_day'] ?? null,
            'max_minutes_per_booking' => $data['max_minutes_per_booking'] ?? null,
            'max_advance_days' => $data['max_advance_days'] ?? null,
            'checkin_grace_minutes' => $data['checkin_grace_minutes'] ?? 15,
            'requires_checkin' => (bool) ($data['requires_checkin'] ?? false),
        ])->save();

        return $rule->refresh();
    }

    /**
     * The bookings that occupy a space during the interval.
     *
     * @return Collection<int, FacilityBooking>
     */
    public function conflicts(string $spaceId, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreBookingId = null): Collection
    {
        return FacilityBooking::query()
            ->with('user:id,name')
            ->where('space_id', $spaceId)
            ->whereIn('status', FacilityBooking::OccupyingStatuses)
            ->where('starts_at', '<', $end->utc())
            ->where('ends_at', '>', $start->utc())
            ->when($ignoreBookingId !== null, static fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Dry run of a booking: every rule it would break and the bookings it would collide with.
     *
     * @return array{available: bool, violations: list<array{code: string, field: string, message: string}>, conflicts: Collection<int, FacilityBooking>}
     */
    public function check(FacilitySpace $space, string $userId, CarbonImmutable $start, CarbonImmutable $end, ?int $attendees = null, ?string $ignoreBookingId = null): array
    {
        $violations = [];
        $conflicts = collect();
        $rules = $this->rulesFor($space->kind);
        $now = CarbonImmutable::now();

        if (! $space->is_bookable || ! $space->is_active) {
            $violations[] = $this->violation('not_bookable', 'space_id', __('facility_errors.booking.not_bookable'));
        }

        if ($start->lt($now->subMinutes(self::PastToleranceMinutes))) {
            $violations[] = $this->violation('in_past', 'starts_at', __('facility_errors.booking.in_past'));
        }

        $minutes = (int) $start->diffInMinutes($end);
        if ($rules['max_minutes_per_booking'] !== null && $minutes > $rules['max_minutes_per_booking']) {
            $violations[] = $this->violation('too_long', 'ends_at', __('facility_errors.booking.too_long', ['minutes' => $rules['max_minutes_per_booking']]));
        }

        if ($rules['max_advance_days'] !== null && $start->gt($now->addDays($rules['max_advance_days']))) {
            $violations[] = $this->violation('too_far', 'starts_at', __('facility_errors.booking.too_far', ['days' => $rules['max_advance_days']]));
        }

        if ($attendees !== null && $space->capacity !== null && $attendees > $space->capacity) {
            $violations[] = $this->violation('over_capacity', 'attendees', __('facility_errors.booking.over_capacity', ['capacity' => $space->capacity]));
        }

        if ($rules['max_bookings_per_user_per_day'] !== null && $this->bookingsOfUserOnDay($userId, $space->kind, $start, $ignoreBookingId) >= $rules['max_bookings_per_user_per_day']) {
            $violations[] = $this->violation('daily_limit', 'starts_at', __('facility_errors.booking.daily_limit', ['limit' => $rules['max_bookings_per_user_per_day']]));
        }

        $conflicts = $this->conflicts((string) $space->id, $start, $end, $ignoreBookingId);
        if ($conflicts->isNotEmpty()) {
            $violations[] = $this->violation('conflict', 'starts_at', __('facility_errors.booking.conflict'));
        }

        return ['available' => $violations === [], 'violations' => $violations, 'conflicts' => $conflicts];
    }

    /**
     * @param  array{title?: string|null, attendees?: int|null}  $extra
     */
    public function book(FacilitySpace $space, string $userId, CarbonImmutable $start, CarbonImmutable $end, array $extra, ?string $actorId, ?string $seriesId = null): FacilityBooking
    {
        return DB::transaction(function () use ($space, $userId, $start, $end, $extra, $actorId, $seriesId): FacilityBooking {
            TransactionLock::acquire("fac_booking:{$space->id}");

            $result = $this->check($space, $userId, $start, $end, $extra['attendees'] ?? null);
            if (! $result['available']) {
                throw $this->exceptionFor($result['violations']);
            }

            return $this->persist($space, $userId, $start, $end, $extra, $actorId, $seriesId);
        });
    }

    /**
     * Creates a recurring booking. Without $skipConflicts the whole series is refused when any occurrence breaks a rule;
     * with it the occurrences that do are left out and reported.
     *
     * @param  array{recurrence: string, weekdays?: list<int>|null, start_date: string, until_date: string, start_time: string, end_time: string, title?: string|null}  $data
     * @return array{series: FacilityBookingSeries, created: int, skipped: list<array{date: string, reasons: list<string>}>}
     */
    public function createSeries(FacilitySpace $space, string $userId, array $data, bool $skipConflicts, ?string $actorId): array
    {
        $occurrences = $this->expand($data);

        if ($occurrences === []) {
            throw ValidationException::withMessages(['start_date' => [__('facility_errors.series.no_occurrences')]]);
        }

        if (count($occurrences) > (int) config('facility.max_series_occurrences')) {
            throw ValidationException::withMessages(['until_date' => [__('facility_errors.series.too_many', ['max' => (int) config('facility.max_series_occurrences')])]]);
        }

        return DB::transaction(function () use ($space, $userId, $data, $skipConflicts, $actorId, $occurrences): array {
            TransactionLock::acquire("fac_booking:{$space->id}");

            $accepted = [];
            $skipped = [];
            foreach ($occurrences as [$start, $end]) {
                $result = $this->check($space, $userId, $start, $end);
                if ($result['available']) {
                    $accepted[] = [$start, $end];

                    continue;
                }

                $skipped[] = ['date' => $this->clock->businessDate($start), 'reasons' => array_values(array_unique(array_column($result['violations'], 'message')))];
            }

            if ($skipped !== [] && ! $skipConflicts) {
                $summary = array_map(static fn (array $row): string => $row['date'].': '.implode(' ', $row['reasons']), array_slice($skipped, 0, 10));

                throw ValidationException::withMessages(['series' => $summary]);
            }

            if ($accepted === []) {
                throw ValidationException::withMessages(['series' => [__('facility_errors.series.nothing_bookable')]]);
            }

            $series = FacilityBookingSeries::create([
                'space_id' => $space->id,
                'user_id' => $userId,
                'title' => $data['title'] ?? null,
                'recurrence' => $data['recurrence'],
                'weekdays' => $this->weekdaysOf($data),
                'start_date' => $data['start_date'],
                'until_date' => $data['until_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'status' => 'active',
                'occurrences' => count($accepted),
                'created_by' => $actorId,
            ]);

            foreach ($accepted as [$start, $end]) {
                $this->persist($space, $userId, $start, $end, ['title' => $data['title'] ?? null], $actorId, (string) $series->id);
            }

            $this->ledger->record('FACILITY_BOOKING_SERIES_CREATED', $series, ['created' => count($accepted), 'skipped' => count($skipped)]);

            return ['series' => $series->refresh(), 'created' => count($accepted), 'skipped' => $skipped];
        });
    }

    /**
     * Cancels the series and every one of its bookings that has not started.
     *
     * @return int Bookings cancelled.
     */
    public function cancelSeries(FacilityBookingSeries $series, ?string $reason): int
    {
        return DB::transaction(function () use ($series, $reason): int {
            $locked = FacilityBookingSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => [__('facility_errors.series.already_cancelled')]]);
            }

            $count = FacilityBooking::query()
                ->where('series_id', $locked->id)
                ->where('status', FacilityBooking::Booked)
                ->where('starts_at', '>=', now())
                ->update(['status' => FacilityBooking::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

            $locked->update(['status' => 'cancelled']);
            $this->ledger->record('FACILITY_BOOKING_SERIES_CANCELLED', $locked, ['cancelled' => $count]);

            return $count;
        });
    }

    public function cancel(FacilityBooking $booking, ?string $reason): FacilityBooking
    {
        return DB::transaction(function () use ($booking, $reason): FacilityBooking {
            $locked = FacilityBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FacilityBooking::Booked) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.booking.cancel_only_booked', ['status' => $locked->status])]]);
            }

            $locked->update(['status' => FacilityBooking::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->ledger->record('FACILITY_BOOKING_CANCELLED', $locked, ['space_id' => $locked->space_id]);

            return $locked->refresh();
        });
    }

    /**
     * The person arrives: allowed from a few minutes before the start until the booking ends.
     */
    public function checkIn(FacilityBooking $booking): FacilityBooking
    {
        return DB::transaction(function () use ($booking): FacilityBooking {
            $locked = FacilityBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($locked->status !== FacilityBooking::Booked) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.booking.checkin_only_booked', ['status' => $locked->status])]]);
            }

            if ($now->lt($locked->starts_at->copy()->subMinutes((int) config('facility.checkin_early_minutes'))) || $now->gte($locked->ends_at)) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.booking.checkin_window')]]);
            }

            $locked->update(['status' => FacilityBooking::CheckedIn, 'checked_in_at' => $now]);
            $this->ledger->record('FACILITY_BOOKING_CHECKED_IN', $locked, ['space_id' => $locked->space_id]);

            return $locked->refresh();
        });
    }

    /**
     * Frees the bookings nobody checked in to once the grace period passed, and completes the bookings that ended.
     *
     * @return array{released: int, completed: int}
     */
    public function releaseNoShows(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $released = 0;

        foreach (FacilitySpace::Kinds as $kind) {
            $rules = $this->rulesFor($kind);

            if (! $rules['requires_checkin']) {
                continue;
            }

            $released += FacilityBooking::query()
                ->where('status', FacilityBooking::Booked)
                ->where('starts_at', '<=', $now->subMinutes($rules['checkin_grace_minutes'])->utc())
                ->whereIn('space_id', FacilitySpace::query()->where('kind', $kind)->select('id'))
                ->update(['status' => FacilityBooking::NoShow, 'released_at' => $now]);
        }

        $completed = FacilityBooking::query()
            ->whereIn('status', FacilityBooking::OccupyingStatuses)
            ->where('ends_at', '<=', $now->utc())
            ->update(['status' => FacilityBooking::Completed]);

        return ['released' => $released, 'completed' => $completed];
    }

    /**
     * Bookable spaces with nothing booked during the interval.
     *
     * @param  array{building_id?: string|null, kind?: string|null, min_capacity?: int|null}  $filters
     * @return Collection<int, FacilitySpace>
     */
    public function availableSpaces(CarbonImmutable $start, CarbonImmutable $end, array $filters = []): Collection
    {
        return FacilitySpace::query()
            ->with('building:id,code,name')
            ->where('is_bookable', true)
            ->where('is_active', true)
            ->when(! empty($filters['building_id']), static fn ($query) => $query->where('building_id', $filters['building_id']))
            ->when(! empty($filters['kind']), static fn ($query) => $query->where('kind', $filters['kind']))
            ->when(! empty($filters['min_capacity']), static fn ($query) => $query->where('capacity', '>=', (int) $filters['min_capacity']))
            ->whereDoesntHave('bookings', static fn ($query) => $query
                ->whereIn('status', FacilityBooking::OccupyingStatuses)
                ->where('starts_at', '<', $end->utc())
                ->where('ends_at', '>', $start->utc()))
            ->orderBy('code')
            ->get();
    }

    /**
     * The intervals of a recurring booking, as UTC instants built from the wall clock of the company.
     *
     * @param  array{recurrence: string, weekdays?: list<int>|null, start_date: string, until_date: string, start_time: string, end_time: string}  $data
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function expand(array $data): array
    {
        $timezone = $this->clock->timezone();
        $weekdays = $this->weekdaysOf($data);
        $first = CarbonImmutable::createFromFormat('Y-m-d', $data['start_date'], $timezone)->startOfDay();
        $last = CarbonImmutable::createFromFormat('Y-m-d', $data['until_date'], $timezone)->startOfDay();

        if ($last->lt($first) || $first->diffInDays($last) > (int) config('facility.max_series_span_days')) {
            throw ValidationException::withMessages(['until_date' => [__('facility_errors.series.span', ['days' => (int) config('facility.max_series_span_days')])]]);
        }

        $occurrences = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }

            $start = CarbonImmutable::parse($day->toDateString().' '.$data['start_time'], $timezone)->utc();
            $end = CarbonImmutable::parse($day->toDateString().' '.$data['end_time'], $timezone)->utc();

            if ($end->lte($start)) {
                throw ValidationException::withMessages(['end_time' => [__('facility_errors.series.end_before_start')]]);
            }

            $occurrences[] = [$start, $end];
        }

        return $occurrences;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function weekdaysOf(array $data): array
    {
        $weekdays = ($data['recurrence'] ?? 'daily') === 'daily' ? [1, 2, 3, 4, 5, 6, 7] : array_map('intval', (array) ($data['weekdays'] ?? []));
        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays);

        return $weekdays;
    }

    /**
     * @param  array{title?: string|null, attendees?: int|null}  $extra
     */
    private function persist(FacilitySpace $space, string $userId, CarbonImmutable $start, CarbonImmutable $end, array $extra, ?string $actorId, ?string $seriesId): FacilityBooking
    {
        $booking = FacilityBooking::create([
            'space_id' => $space->id,
            'series_id' => $seriesId,
            'user_id' => $userId,
            'title' => $extra['title'] ?? null,
            'attendees' => $extra['attendees'] ?? null,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'status' => FacilityBooking::Booked,
            'created_by' => $actorId,
        ]);

        $this->ledger->record('FACILITY_BOOKING_CREATED', $booking, ['space_id' => $space->id, 'kind' => $space->kind, 'series' => $seriesId !== null]);

        return $booking;
    }

    private function bookingsOfUserOnDay(string $userId, string $kind, CarbonImmutable $start, ?string $ignoreBookingId): int
    {
        $dayStart = $start->setTimezone($this->clock->timezone())->startOfDay();

        return FacilityBooking::query()
            ->where('user_id', $userId)
            ->whereIn('status', FacilityBooking::OccupyingStatuses)
            ->where('starts_at', '>=', $dayStart->utc())
            ->where('starts_at', '<', $dayStart->addDay()->utc())
            ->whereIn('space_id', FacilitySpace::query()->where('kind', $kind)->select('id'))
            ->when($ignoreBookingId !== null, static fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->count();
    }

    /**
     * @return array{code: string, field: string, message: string}
     */
    private function violation(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }

    /**
     * @param  list<array{code: string, field: string, message: string}>  $violations
     */
    private function exceptionFor(array $violations): ValidationException
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[$violation['field']][] = $violation['message'];
        }

        return ValidationException::withMessages($messages);
    }
}
