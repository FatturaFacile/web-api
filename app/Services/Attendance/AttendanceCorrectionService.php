<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\TimeEntryCorrectionStatus;
use App\Enums\TimeEntryEventType;
use App\Events\AttendanceShiftChanged;
use App\Exceptions\AttendanceStateException;
use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\TimeEntryEvent;
use App\Models\User;
use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionService
{
    public function __construct(private readonly AuditLedgerInterface $auditLedger) {}

    /** @param array{clock_in: string, clock_out: string, break_minutes: int|string, reason: string} $data */
    public function submit(TimeEntry $entry, User $actor, array $data): TimeEntryCorrection
    {
        return DB::transaction(function () use ($entry, $actor, $data): TimeEntryCorrection {
            $locked = $this->lockEntry($entry);
            Gate::authorize('create', [TimeEntryCorrection::class, $locked]);

            if ($locked->corrections()->where('status', TimeEntryCorrectionStatus::Pending)->exists()) {
                throw new AttendanceStateException('Correction Already Pending', 'Review the existing request before submitting another one.');
            }

            $values = [
                'clock_in' => Carbon::parse($data['clock_in'])->utc()->toIso8601String(),
                'clock_out' => Carbon::parse($data['clock_out'])->utc()->toIso8601String(),
                'break_minutes' => (int) $data['break_minutes'],
            ];
            $this->validateProposal($locked, $values);

            if ($values === [
                'clock_in' => $locked->effectiveClockIn()->copy()->utc()->toIso8601String(),
                'clock_out' => $locked->effectiveClockOut()?->copy()->utc()->toIso8601String(),
                'break_minutes' => $locked->effectiveBreakMinutes(),
            ]) {
                throw ValidationException::withMessages(['clock_in' => 'The proposal does not change the recorded attendance.']);
            }

            $correction = $locked->corrections()->create([
                'requested_by' => $actor->id,
                'status' => TimeEntryCorrectionStatus::Pending,
                'reason' => $data['reason'],
                'original_values' => $this->snapshot($locked),
                'proposed_values' => $values,
            ]);
            $this->auditLedger->record('ATTENDANCE_CORRECTION_REQUESTED', $correction, payloadAfter: $correction->toArray(), moduleCode: 'TIME_ATTENDANCE');

            return $correction;
        });
    }

    public function review(TimeEntryCorrection $correction, User $actor, bool $approve, ?string $reason): TimeEntryCorrection
    {
        return DB::transaction(function () use ($correction, $actor, $approve, $reason): TimeEntryCorrection {
            $entry = $this->lockEntry($correction->timeEntry()->firstOrFail());
            $locked = TimeEntryCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ($actor->id === $locked->requested_by || $actor->id === $entry->user_id) {
                throw new AuthorizationException('A correction requires an independent reviewer.');
            }
            Gate::authorize('review', $locked);

            if ($locked->status !== TimeEntryCorrectionStatus::Pending) {
                throw new AttendanceStateException('Correction Already Reviewed', 'This correction already has a final decision.');
            }

            $before = $locked->toArray();
            if ($approve) {
                if ($locked->original_values !== $this->snapshot($entry)) {
                    throw new AttendanceStateException('Attendance Has Changed', 'The original attendance changed after this request. Reject it and submit a new proposal.');
                }

                $previousWorkDate = $entry->effectiveClockIn()->toDateString();
                $this->validateProposal($entry, $locked->proposed_values);
                $entry->corrected_clock_in = Carbon::parse($locked->proposed_values['clock_in']);
                $entry->corrected_clock_out = Carbon::parse($locked->proposed_values['clock_out']);
                $entry->corrected_break_minutes = $locked->proposed_values['break_minutes'];
                $entry->applied_correction_id = $locked->id;
                $entry->save();
            }

            $locked->status = $approve ? TimeEntryCorrectionStatus::Approved : TimeEntryCorrectionStatus::Rejected;
            $locked->reviewed_by = $actor->id;
            $locked->review_reason = $reason;
            $locked->reviewed_at = now();
            $locked->save();
            $this->auditLedger->record(
                $approve ? 'ATTENDANCE_CORRECTION_APPROVED' : 'ATTENDANCE_CORRECTION_REJECTED',
                $locked,
                payloadBefore: $before,
                payloadAfter: $locked->toArray(),
                moduleCode: 'TIME_ATTENDANCE'
            );

            if ($approve) {
                Event::dispatch(new AttendanceShiftChanged(
                    tenantId: (string) $entry->tenant_id,
                    userId: (string) $entry->user_id,
                    workDates: [$previousWorkDate, Carbon::parse($locked->proposed_values['clock_in'])->toDateString()]
                ));
            }

            return $locked;
        });
    }

    private function lockEntry(TimeEntry $entry): TimeEntry
    {
        User::whereKey($entry->user_id)->lockForUpdate()->firstOrFail();

        return TimeEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
    }

    /** @param array{clock_in: string, clock_out: string, break_minutes: int} $values */
    private function validateProposal(TimeEntry $entry, array $values): void
    {
        $start = Carbon::parse($values['clock_in']);
        $end = Carbon::parse($values['clock_out']);
        if ($end->lessThanOrEqualTo($start) || $end->isFuture() || $start->isFuture()) {
            throw ValidationException::withMessages(['clock_out' => 'The proposed interval must be completed and cannot be in the future.']);
        }
        if ($values['break_minutes'] < 0 || $values['break_minutes'] > $start->diffInMinutes($end)) {
            throw ValidationException::withMessages(['break_minutes' => 'Break minutes cannot exceed the proposed shift duration.']);
        }

        $events = $entry->events()->get();
        if ($events->last()?->type === TimeEntryEventType::BreakStarted) {
            throw new AttendanceStateException('Break Still Open', 'End the active break before requesting an attendance correction.');
        }
        foreach ($events as $event) {
            if ($event->occurred_at->isBefore($start) || $event->occurred_at->isAfter($end)) {
                throw ValidationException::withMessages(['clock_out' => 'The proposed interval must contain the original break events.']);
            }
        }

        $overlap = TimeEntry::where('user_id', $entry->user_id)
            ->where('id', '!=', $entry->id)
            ->whereRaw('COALESCE(corrected_clock_in, clock_in) < ?', [$end])
            ->where(function (Builder $query) use ($start): void {
                $query->whereRaw('COALESCE(corrected_clock_out, clock_out) IS NULL')
                    ->orWhereRaw('COALESCE(corrected_clock_out, clock_out) > ?', [$start]);
            })->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['clock_in' => 'The proposed attendance overlaps another shift.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(TimeEntry $entry): array
    {
        return [
            'clock_in' => $entry->clock_in->toIso8601String(),
            'clock_out' => $entry->clock_out?->toIso8601String(),
            'break_minutes' => (int) $entry->break_minutes,
            'corrected_clock_in' => $entry->corrected_clock_in?->toIso8601String(),
            'corrected_clock_out' => $entry->corrected_clock_out?->toIso8601String(),
            'corrected_break_minutes' => $entry->corrected_break_minutes,
            'applied_correction_id' => $entry->applied_correction_id,
            'events' => $entry->events()->get()->map(static fn (TimeEntryEvent $event): array => [
                'id' => $event->id,
                'type' => $event->type->value,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->all(),
        ];
    }
}
