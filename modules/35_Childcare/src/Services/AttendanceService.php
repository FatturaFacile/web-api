<?php

declare(strict_types=1);

namespace Modules\Childcare\Services;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\AttendanceCorrection;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ReferencePerson;

/**
 * Check-in and check-out from the tablet, absences, authorised corrections and the monthly register.
 */
final class AttendanceService
{
    /**
     * Checks a child in for the day of the moment, once per day and only while enrolled.
     */
    public function checkIn(Child $child, ?string $at, ?string $temperature, ?string $userId): Attendance
    {
        $moment = $at === null ? CarbonImmutable::now() : CarbonImmutable::parse($at);
        $this->assertEnrolled($child, $moment);

        return DB::transaction(function () use ($child, $moment, $temperature, $userId): Attendance {
            $attendance = Attendance::query()->lockForUpdate()->where('child_id', $child->id)->whereDate('date', $moment->toDateString())->first()
                ?? new Attendance(['child_id' => $child->id, 'date' => $moment->toDateString()]);

            if ($attendance->check_in_at !== null) {
                throw new BusinessRuleException('The child is already checked in today.');
            }

            $attendance->fill(['status' => 'present', 'absence_reason' => null, 'check_in_at' => $moment, 'checked_in_by' => $userId, 'temperature' => $temperature]);
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * Checks a child out, handing it to a person authorised to pick it up; locked so a child leaves once.
     */
    public function checkOut(Child $child, ?string $at, string $referencePersonId, ?string $userId): Attendance
    {
        $moment = $at === null ? CarbonImmutable::now() : CarbonImmutable::parse($at);

        return DB::transaction(function () use ($child, $moment, $referencePersonId, $userId): Attendance {
            $attendance = Attendance::query()->lockForUpdate()->where('child_id', $child->id)->whereDate('date', $moment->toDateString())->first();

            if ($attendance === null || $attendance->check_in_at === null) {
                throw new BusinessRuleException('The child is not checked in today.');
            }

            if ($attendance->check_out_at !== null) {
                throw new BusinessRuleException('The child is already checked out.');
            }

            $person = ReferencePerson::query()->where('child_id', $child->id)->find($referencePersonId);

            if ($person === null || ! $person->can_pick_up) {
                throw new BusinessRuleException('This person is not authorised to pick the child up.');
            }

            if ($moment->lt($attendance->check_in_at)) {
                throw new BusinessRuleException('The check-out precedes the check-in.');
            }

            $attendance->update(['check_out_at' => $moment, 'checked_out_by' => $userId, 'picked_up_by' => $person->id]);

            return $attendance;
        });
    }

    /**
     * Records the absence of a child for a day, with the reason; a checked-in child is corrected instead.
     */
    public function markAbsent(Child $child, string $date, string $reason): Attendance
    {
        $day = CarbonImmutable::parse($date);
        $this->assertEnrolled($child, $day);

        return DB::transaction(function () use ($child, $day, $reason): Attendance {
            $attendance = Attendance::query()->lockForUpdate()->firstOrNew(['child_id' => $child->id, 'date' => $day->toDateString()]);

            if ($attendance->check_in_at !== null) {
                throw new BusinessRuleException('A checked-in child is corrected, not marked absent.');
            }

            $attendance->fill(['status' => 'absent', 'absence_reason' => $reason]);
            $attendance->save();

            return $attendance;
        });
    }

    /**
     * Changes an attendance, keeping each previous value with the reason and the author of the change.
     *
     * @param  array<string, mixed>  $changes
     */
    public function correct(Attendance $attendance, array $changes, string $reason, ?string $userId): Attendance
    {
        return DB::transaction(function () use ($attendance, $changes, $reason, $userId): Attendance {
            $attendance = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);

            foreach (array_intersect_key($changes, array_flip(Attendance::CorrectableFields)) as $field => $value) {
                $old = $attendance->getAttribute($field);
                $oldText = $old instanceof CarbonImmutable ? $old->toIso8601String() : ($old === null ? null : (string) $old);
                $newValue = in_array($field, ['check_in_at', 'check_out_at'], true) && $value !== null ? CarbonImmutable::parse((string) $value) : $value;
                $newText = $newValue instanceof CarbonImmutable ? $newValue->toIso8601String() : ($newValue === null ? null : (string) $newValue);

                if ($oldText === $newText) {
                    continue;
                }

                $correction = new AttendanceCorrection;
                $correction->fill(['attendance_id' => $attendance->id, 'field' => $field, 'old_value' => $oldText, 'new_value' => $newText, 'reason' => $reason, 'corrected_by' => $userId]);
                $correction->save();
                $attendance->setAttribute($field, $newValue);
            }

            if ($attendance->check_in_at !== null && $attendance->check_out_at !== null && $attendance->check_out_at->lt($attendance->check_in_at)) {
                throw new BusinessRuleException('The check-out precedes the check-in.');
            }

            $attendance->save();

            return $attendance;
        });
    }

    /**
     * The register of a month: per child and day the status and the times.
     *
     * @return array{month: string, children: list<array<string, mixed>>}
     */
    public function register(string $month, ?string $groupId): array
    {
        $start = CarbonImmutable::parse("{$month}-01");
        $end = $start->endOfMonth();
        $children = Child::query()
            ->when($groupId !== null, fn ($query) => $query->where('group_id', $groupId))
            ->whereDate('enrolled_on', '<=', $end)
            ->where(fn ($query) => $query->whereNull('left_on')->orWhereDate('left_on', '>=', $start))
            ->get();
        $attendances = Attendance::query()->whereIn('child_id', $children->pluck('id'))->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('child_id');

        $rows = $children->map(static function (Child $child) use ($attendances): array {
            $days = [];
            $present = 0;

            foreach ($attendances->get($child->id, collect()) as $attendance) {
                /** @var Attendance $attendance */
                $days[$attendance->date->toDateString()] = [
                    'status' => $attendance->status,
                    'absence_reason' => $attendance->absence_reason,
                    'check_in_at' => $attendance->check_in_at?->format('H:i'),
                    'check_out_at' => $attendance->check_out_at?->format('H:i'),
                ];
                $present += $attendance->status === 'present' ? 1 : 0;
            }

            ksort($days);

            return ['child_id' => $child->id, 'reference_code' => $child->reference_code, 'name' => "{$child->last_name} {$child->first_name}", 'present_days' => $present, 'days' => $days];
        })->sortBy('name')->values()->all();

        return ['month' => $month, 'children' => $rows];
    }

    private function assertEnrolled(Child $child, CarbonImmutable $day): void
    {
        if (! $child->isEnrolledOn($day->startOfDay())) {
            throw new BusinessRuleException('The child is not enrolled on that day.');
        }
    }
}
