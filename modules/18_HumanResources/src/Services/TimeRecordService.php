<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\TimeTracking\HashChainIntegrityService;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimePeriodClosingService;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

/**
 * Writes the working time records: every insertion, correction and deletion goes to the history in the same
 * transaction, with the values before and after, the author and the reason.
 */
final class TimeRecordService
{
    public function __construct(
        private readonly TimePeriodClosingService $closings,
        private readonly HashChainIntegrityService $hashChain,
        private readonly TenantContext $tenantContext,
    ) {}

    private const STATE_OFF = 'Off';

    private const STATE_WORKING = 'Working';

    private const STATE_ON_BREAK = 'OnBreak';

    /**
     * The employee clocks now: the sequence entry, (break start, break end)*, exit is enforced.
     *
     * @param  array{lat: float, lng: float, accuracy_meters?: float}|null  $gps  Point-in-time GPS only when consent is on file.
     */
    public function clock(User $employee, TimeEventType $eventType, TimeRecordSource $source, ?string $note, ?array $gps = null): TimeClockEvent
    {
        return DB::transaction(function () use ($employee, $eventType, $source, $note, $gps): TimeClockEvent {
            // Two clicks on the badge must not both see the previous state.
            User::query()->whereKey($employee->id)->lockForUpdate()->first();

            $now = CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE);
            $state = $this->clockState((string) $employee->id, $now);

            if (! $this->isAllowed($state, $eventType)) {
                throw ValidationException::withMessages([
                    'event_type' => __('hr_errors.time_record.invalid_in_state', ['state' => $state]),
                ]);
            }

            return $this->storeEvent($employee, (string) $employee->id, $now->startOfMinute(), $eventType, $source, $note, null, $gps);
        });
    }

    /**
     * A manager enters a record for the past or for another employee: the sequence is not enforced, anomalies show up
     * in the report.
     */
    public function record(
        User $actor,
        string $employeeId,
        CarbonImmutable $occurredAt,
        TimeEventType $eventType,
        TimeRecordSource $source,
        ?string $note,
        string $reason,
    ): TimeClockEvent {
        return DB::transaction(fn (): TimeClockEvent => $this->storeEvent($actor, $employeeId, $occurredAt, $eventType, $source, $note, $reason));
    }

    /**
     * Append-only correction: soft-deletes the original event (reason='amended') and creates a replacement
     * linked via amended_from_id. Complies with CJEU C-55/18 immutability requirements.
     *
     * @param  array{occurred_at?: CarbonImmutable, event_type?: TimeEventType, source: TimeRecordSource, note?: string|null}  $values
     */
    public function correct(User $actor, TimeClockEvent $event, array $values, string $reason): TimeClockEvent
    {
        if ($event->trashed()) {
            throw ValidationException::withMessages([
                'id' => __('hr_errors.time_record.deleted_not_editable'),
            ]);
        }

        return DB::transaction(function () use ($actor, $event, $values, $reason): TimeClockEvent {
            $this->closings->assertOpen($event->occurred_at);

            if (isset($values['occurred_at'])) {
                $this->closings->assertOpen($values['occurred_at']);
            }

            $before = $event->auditValues();

            // Soft-delete original: append-only means the original record stays in the history
            $event->fill(['deleted_by' => $actor->id, 'deletion_reason' => 'amended'])->save();
            $event->delete();

            $newEvent = TimeClockEvent::create([
                'user_id' => $event->user_id,
                'occurred_at' => $values['occurred_at'] ?? $event->occurred_at,
                'event_type' => $values['event_type'] ?? $event->event_type,
                'source' => $values['source'],
                'note' => array_key_exists('note', $values) ? $values['note'] : $event->note,
                'created_by' => $actor->id,
                'amended_from_id' => $event->id,
            ]);

            $this->audit($actor, (string) $event->user_id, 'TimeClockEvent', (string) $newEvent->id, 'Amended', $before, $newEvent->auditValues(), $reason);

            return $newEvent;
        });
    }

    /**
     * The event stays in the database with who deleted it and why.
     */
    public function delete(User $actor, TimeClockEvent $event, string $reason): void
    {
        DB::transaction(function () use ($actor, $event, $reason): void {
            $this->closings->assertOpen($event->occurred_at);
            $event->fill(['deleted_by' => $actor->id, 'deletion_reason' => $reason])->save();
            $event->delete();
            $this->audit($actor, (string) $event->user_id, 'TimeClockEvent', (string) $event->id, 'Deleted', $event->auditValues(), null, $reason);
        });
    }

    /**
     * Create or correct the record of a day: changing an existing day needs a reason.
     *
     * @param  array{worked_minutes: int, start_time: CarbonImmutable|null, end_time: CarbonImmutable|null, note: string|null}  $values
     */
    public function saveDailyRecord(User $actor, string $employeeId, string $workDate, array $values, ?string $reason): TimeDailyRecord
    {
        return DB::transaction(function () use ($actor, $employeeId, $workDate, $values, $reason): TimeDailyRecord {
            $this->closings->assertDayOpen($workDate);
            User::query()->whereKey($employeeId)->lockForUpdate()->first();

            $record = TimeDailyRecord::query()->where('user_id', $employeeId)->whereDate('work_date', $workDate)->first();

            if ($record === null) {
                $record = TimeDailyRecord::create(['user_id' => $employeeId, 'work_date' => $workDate, 'created_by' => $actor->id] + $values);
                $this->audit($actor, $employeeId, 'TimeDailyRecord', (string) $record->id, 'Created', null, $record->auditValues(), $reason);

                return $record;
            }

            if ($reason === null) {
                throw ValidationException::withMessages(['reason' => __('hr_errors.time_record.correction_reason_required')]);
            }

            $before = $record->auditValues();
            $record->fill($values)->save();
            $this->audit($actor, $employeeId, 'TimeDailyRecord', (string) $record->id, 'Modified', $before, $record->auditValues(), $reason);

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveProfile(User $actor, string $employeeId, array $values, ?string $reason): TimeRecordingProfile
    {
        return DB::transaction(function () use ($actor, $employeeId, $values, $reason): TimeRecordingProfile {
            $profile = TimeRecordingProfile::query()->where('user_id', $employeeId)->first();
            $before = $profile?->auditValues();

            $profile ??= new TimeRecordingProfile(['user_id' => $employeeId]);
            $profile->fill($values)->save();
            $this->audit($actor, $employeeId, 'TimeRecordingProfile', (string) $profile->id, $before === null ? 'Created' : 'Modified', $before, $profile->auditValues(), $reason);

            return $profile;
        });
    }

    /**
     * Mirror a clock-in or clock-out of the deprecated /time-attendance endpoints as a clock event linked to its legacy
     * entry, so the Human Resources records stay the single source of working time. Idempotent per entry and type.
     */
    public function mirrorLegacyEntry(User $employee, string $legacyEntryId, TimeEventType $eventType, CarbonImmutable $occurredAt, ?string $note): TimeClockEvent
    {
        return DB::transaction(function () use ($employee, $legacyEntryId, $eventType, $occurredAt, $note): TimeClockEvent {
            $existing = TimeClockEvent::query()->where('legacy_time_entry_id', $legacyEntryId)->where('event_type', $eventType->value)->first();

            if ($existing !== null) {
                return $existing;
            }

            $event = $this->storeEvent($employee, (string) $employee->id, $occurredAt, $eventType, TimeRecordSource::Web, $note, 'Registrata da /time-attendance (deprecato)');
            $event->forceFill(['legacy_time_entry_id' => $legacyEntryId])->save();

            return $event;
        });
    }

    /**
     * @param  array{lat: float, lng: float, accuracy_meters?: float}|null  $gps
     */
    private function storeEvent(
        User $actor,
        string $employeeId,
        CarbonImmutable $occurredAt,
        TimeEventType $eventType,
        TimeRecordSource $source,
        ?string $note,
        ?string $reason,
        ?array $gps = null,
    ): TimeClockEvent {
        $this->closings->assertOpen($occurredAt);

        $gpsData = $this->resolveGps($employeeId, $gps);

        $event = TimeClockEvent::create([
            'user_id' => $employeeId,
            'occurred_at' => $occurredAt,
            'event_type' => $eventType,
            'source' => $source,
            'note' => $note,
            'created_by' => $actor->id,
            'gps_lat' => $gpsData['lat'] ?? null,
            'gps_lng' => $gpsData['lng'] ?? null,
            'geolocation_consent_at' => $gpsData !== null ? CarbonImmutable::now() : null,
        ]);

        $this->audit($actor, $employeeId, 'TimeClockEvent', (string) $event->id, 'Created', null, $event->auditValues(), $reason);

        return $event;
    }

    /**
     * Art. 4 L. 300/1970 & GDPR: GPS is stored only when the employee's profile has geolocation_enabled=true
     * AND privacy_notice_acknowledged_at is set. Returns null if either condition is unmet.
     *
     * @param  array{lat: float, lng: float, accuracy_meters?: float}|null  $gps
     * @return array{lat: float, lng: float}|null
     */
    private function resolveGps(string $employeeId, ?array $gps): ?array
    {
        if ($gps === null) {
            return null;
        }

        $profile = TimeRecordingProfile::query()
            ->where('user_id', $employeeId)
            ->select(['geolocation_enabled', 'privacy_notice_acknowledged_at'])
            ->first();

        if ($profile === null || ! $profile->geolocation_enabled || $profile->privacy_notice_acknowledged_at === null) {
            return null;
        }

        return ['lat' => (float) $gps['lat'], 'lng' => (float) $gps['lng']];
    }

    private function clockState(string $employeeId, CarbonImmutable $now): string
    {
        $last = TimeClockEvent::query()
            ->where('user_id', $employeeId)
            ->betweenLocalDays($now->subDay()->format('Y-m-d'), null)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return match (true) {
            $last === null => self::STATE_OFF,
            $last->event_type->startsWork() => self::STATE_WORKING,
            $last->event_type === TimeEventType::BreakStart => self::STATE_ON_BREAK,
            default => self::STATE_OFF,
        };
    }

    private function isAllowed(string $state, TimeEventType $eventType): bool
    {
        return match ($state) {
            self::STATE_OFF => $eventType === TimeEventType::In,
            self::STATE_WORKING => $eventType === TimeEventType::Out || $eventType === TimeEventType::BreakStart,
            self::STATE_ON_BREAK => $eventType === TimeEventType::BreakEnd || $eventType === TimeEventType::Out,
            default => false,
        };
    }

    /**
     * Writes an immutable audit entry and extends the SHA-256 hash chain (CJEU C-55/18).
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(User $actor, string $employeeId, string $entityType, string $entityId, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $occurredAt = CarbonImmutable::now();
        $prevHash = $this->hashChain->latestHash($tenantId);

        $hashPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $employeeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'actor_id' => (string) $actor->id,
            'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s'),
        ];

        $recordHash = $this->hashChain->computeHash($hashPayload, $prevHash);

        TimeAuditEntry::create([
            'user_id' => $employeeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'reason' => $reason,
            'actor_id' => $actor->id,
            'actor_name' => (string) $actor->name,
            'occurred_at' => $occurredAt,
            'prev_hash' => $prevHash,
            'record_hash' => $recordHash,
        ]);
    }
}
