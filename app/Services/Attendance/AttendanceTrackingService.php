<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\TimeEntryEventType;
use App\Exceptions\AttendanceStateException;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceTrackingService
{
    /**
     * @param  array{clock_in?: string|null, latitude?: float|int|string|null, longitude?: float|int|string|null, is_simplified_record?: bool|int|string|null, notes?: string|null}  $data
     */
    public function clockIn(User $user, array $data): TimeEntry
    {
        return DB::transaction(function () use ($user, $data): TimeEntry {
            $this->lockUser($user);

            $activeEntry = TimeEntry::where('user_id', $user->id)->whereNull('clock_out')->first();

            if ($activeEntry !== null) {
                throw new AttendanceStateException(
                    'Already Clocked In',
                    sprintf('You have an open shift started at %s. Please clock out before starting a new one.', $activeEntry->clock_in->toDateTimeString())
                );
            }

            return TimeEntry::create([
                'user_id' => $user->id,
                'clock_in' => $this->timestamp($data['clock_in'] ?? null),
                'clock_in_lat' => $data['latitude'] ?? null,
                'clock_in_lng' => $data['longitude'] ?? null,
                'is_simplified_record' => (bool) ($data['is_simplified_record'] ?? false),
                'notes' => $data['notes'] ?? null,
            ])->load(['user', 'events']);
        });
    }

    /** @param array{clock_out?: string|null, break_minutes?: int|null, notes?: string|null} $data */
    public function clockOut(User $user, array $data): TimeEntry
    {
        return DB::transaction(function () use ($user, $data): TimeEntry {
            $this->lockUser($user);
            $entry = $this->activeEntry($user);
            $lastEvent = $entry->events()->reorder('sequence', 'desc')->first();

            if ($lastEvent?->type === TimeEntryEventType::BreakStarted) {
                throw new AttendanceStateException('Break Still Open', 'End the active break before clocking out.');
            }

            $clockOut = $this->timestamp($data['clock_out'] ?? null);

            if ($clockOut->isBefore($entry->clock_in)) {
                throw new AttendanceStateException('Invalid Clock-Out Timestamp', 'Clock-out time cannot be earlier than clock-in time.', 422);
            }

            if ($lastEvent !== null && $clockOut->isBefore($lastEvent->occurred_at)) {
                throw ValidationException::withMessages(['clock_out' => 'Clock-out time cannot precede the last break event.']);
            }

            if ($lastEvent !== null && isset($data['break_minutes'])) {
                throw ValidationException::withMessages(['break_minutes' => 'Break minutes are calculated from the recorded break events.']);
            }

            $entry->clock_out = $clockOut;
            if ($lastEvent === null) {
                $entry->break_minutes = (int) ($data['break_minutes'] ?? 0);
            }
            if (! empty($data['notes'])) {
                $entry->notes = ($entry->notes ? $entry->notes."\n" : '').$data['notes'];
            }
            $entry->save();

            return $entry->load(['user', 'events']);
        });
    }

    /** @param array{occurred_at?: string|null} $data */
    public function recordBreak(User $user, TimeEntryEventType $type, array $data): TimeEntry
    {
        return DB::transaction(function () use ($user, $type, $data): TimeEntry {
            $this->lockUser($user);
            $entry = $this->activeEntry($user);
            $lastEvent = $entry->events()->reorder('sequence', 'desc')->first();
            $isOnBreak = $lastEvent?->type === TimeEntryEventType::BreakStarted;

            if ($type === TimeEntryEventType::BreakStarted && $isOnBreak) {
                throw new AttendanceStateException('Already On Break', 'End the current break before starting another one.');
            }

            if ($type === TimeEntryEventType::BreakEnded && ! $isOnBreak) {
                throw new AttendanceStateException('No Active Break', 'Start a break before ending it.');
            }

            $occurredAt = $this->timestamp($data['occurred_at'] ?? null);
            $earliestTime = $lastEvent?->occurred_at ?? $entry->clock_in;

            if ($occurredAt->isBefore($earliestTime)) {
                throw ValidationException::withMessages(['occurred_at' => 'Break events cannot precede clock-in or the previous event.']);
            }

            $entry->events()->create([
                'sequence' => ($lastEvent?->sequence ?? 0) + 1,
                'type' => $type,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'recorded_by' => $user->id,
            ]);

            if ($type === TimeEntryEventType::BreakEnded) {
                $totalBreakSeconds = 0.0;
                $breakStartedAt = null;

                foreach ($entry->events()->get() as $event) {
                    if ($event->type === TimeEntryEventType::BreakStarted) {
                        $breakStartedAt = $event->occurred_at;
                    } elseif ($breakStartedAt !== null) {
                        $totalBreakSeconds += $breakStartedAt->diffInSeconds($event->occurred_at);
                        $breakStartedAt = null;
                    }
                }

                $entry->break_minutes = (int) floor($totalBreakSeconds / 60.0);
                $entry->save();
            }

            return $entry->load(['user', 'events']);
        });
    }

    private function lockUser(User $user): void
    {
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function activeEntry(User $user): TimeEntry
    {
        $entry = TimeEntry::where('user_id', $user->id)
            ->whereNull('clock_out')
            ->latest('clock_in')
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            throw new AttendanceStateException('No Active Shift Found', 'No running shift found. Please clock in first.', 422);
        }

        return $entry;
    }

    private function timestamp(?string $value): Carbon
    {
        return ($value === null ? now() : Carbon::parse($value))
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
