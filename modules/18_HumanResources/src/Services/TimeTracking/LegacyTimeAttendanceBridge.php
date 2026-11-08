<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Services\TimeRecordService;

/**
 * Keeps the deprecated /api/v1/time-attendance clock-in and clock-out working while the Human Resources clock events
 * stay the single source of working time: every legacy entry is written together with its mirrored "In" / "Out" event
 * (linked by legacy_time_entry_id) in one transaction, under the row lock of the user and the monthly closing.
 */
final readonly class LegacyTimeAttendanceBridge
{
    public function __construct(
        private TimeRecordService $records,
        private TimePeriodClosingService $closings,
    ) {}

    /**
     * Open a legacy entry; null when the user already has an open one.
     *
     * @param  array{clock_in?: string|null, latitude?: float|string|null, longitude?: float|string|null, is_simplified_record?: bool|null, notes?: string|null}  $values
     */
    public function clockIn(User $user, array $values): ?TimeEntry
    {
        return DB::transaction(function () use ($user, $values): ?TimeEntry {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (TimeEntry::query()->where('user_id', $user->id)->whereNull('clock_out')->exists()) {
                return null;
            }

            $clockIn = empty($values['clock_in']) ? CarbonImmutable::now() : CarbonImmutable::parse((string) $values['clock_in']);
            $this->closings->assertOpen($clockIn);

            $entry = new TimeEntry;
            $entry->user_id = $user->id;
            $entry->forceFill(['clock_in' => $clockIn]);
            $entry->clock_in_lat = $values['latitude'] ?? null;
            $entry->clock_in_lng = $values['longitude'] ?? null;
            $entry->is_simplified_record = (bool) ($values['is_simplified_record'] ?? false);
            $entry->notes = $values['notes'] ?? null;
            $entry->save();

            $this->records->mirrorLegacyEntry($user, (string) $entry->id, TimeEventType::In, $clockIn->utc(), $entry->notes);

            return $entry;
        });
    }

    /**
     * Close the open legacy entry; null when there is none.
     *
     * @param  array{clock_out?: string|null, break_minutes?: int|null, notes?: string|null}  $values
     */
    public function clockOut(User $user, array $values): ?TimeEntry
    {
        return DB::transaction(function () use ($user, $values): ?TimeEntry {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $entry = TimeEntry::query()->where('user_id', $user->id)->whereNull('clock_out')->latest('clock_in')->lockForUpdate()->first();

            if ($entry === null) {
                return null;
            }

            $clockOut = empty($values['clock_out']) ? CarbonImmutable::now() : CarbonImmutable::parse((string) $values['clock_out']);

            if ($clockOut->lessThan($entry->clock_in)) {
                throw new HumanResourcesException('Clock-out time cannot be earlier than clock-in time.');
            }

            $this->closings->assertOpen($clockOut);

            $entry->forceFill(['clock_out' => $clockOut]);
            $entry->break_minutes = (int) ($values['break_minutes'] ?? 0);

            if (! empty($values['notes'])) {
                $entry->notes = ($entry->notes ? $entry->notes."\n" : '').$values['notes'];
            }

            $entry->save();

            $note = $entry->break_minutes > 0 ? "Pausa dichiarata: {$entry->break_minutes} min." : null;
            $this->records->mirrorLegacyEntry($user, (string) $entry->id, TimeEventType::Out, $clockOut->utc(), $note);

            return $entry;
        });
    }
}
