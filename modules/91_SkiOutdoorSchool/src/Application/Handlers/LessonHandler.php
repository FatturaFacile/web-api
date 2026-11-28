<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\SkiOutdoorSchool\Application\Commands\BookLessonCommand;
use Modules\SkiOutdoorSchool\Application\Services\CommissionRecorder;
use Modules\SkiOutdoorSchool\Application\Services\InstructorScheduleService;
use Modules\SkiOutdoorSchool\Application\Services\RotationCandidateBuilder;
use Modules\SkiOutdoorSchool\Application\Services\SchoolSettingsService;
use Modules\SkiOutdoorSchool\Domain\Enums\AssignmentMethod;
use Modules\SkiOutdoorSchool\Domain\Enums\BookingSource;
use Modules\SkiOutdoorSchool\Domain\Enums\CommissionSource;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemStatus;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;
use Modules\SkiOutdoorSchool\Events\LessonBooked;
use Modules\SkiOutdoorSchool\Events\LessonCompleted;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\PackageItem;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * Private lessons: booking (nominated instructor, fair rotation or manual), (re)assignment, rescheduling, cancellation and completion
 * (which records the fee of the instructor with the secretariat retention). Every change of instructor or time re-checks the conflicts
 * inside the transaction, with the instructor row locked.
 */
final class LessonHandler
{
    public function __construct(
        private readonly RotationCandidateBuilder $rotation,
        private readonly InstructorScheduleService $schedule,
        private readonly SchoolSettingsService $settings,
        private readonly PackageHandler $packages,
        private readonly CommissionRecorder $commissions,
    ) {}

    /**
     * @throws SkiSchoolException invalid_slot, invalid_discipline, instructor_not_found, instructor_not_qualified, instructor_conflict, requested_instructor_unavailable, no_instructor_available
     */
    public function book(BookLessonCommand $command): SkiLesson
    {
        $this->assertSlot($command->slotMinutes, $command->slotCount);
        Discipline::tryFrom($command->discipline) ?? throw SkiSchoolException::because('invalid_discipline');

        return DB::transaction(function () use ($command): SkiLesson {
            $duration = $command->slotMinutes * $command->slotCount;
            $range = TimeRange::fromStart($command->startTime, $duration);
            [$instructor, $method] = $this->resolveInstructor($command, $range);
            $settings = $this->settings->current();

            $lesson = SkiLesson::query()->create([
                'reference' => $this->settings->reference('SKL'),
                'instructor_id' => $instructor?->id,
                'requested_instructor_id' => $command->requestedInstructorId,
                'is_nominated' => $command->requestedInstructorId !== null && $instructor !== null && (string) $instructor->id === $command->requestedInstructorId,
                'assignment_method' => $method->value,
                'discipline' => $command->discipline,
                'language_code' => $command->language,
                'level_id' => $command->levelId,
                'lesson_date' => $command->lessonDate,
                'start_time' => $range->start(),
                'end_time' => $range->end(),
                'slot_minutes' => $command->slotMinutes,
                'slot_count' => $command->slotCount,
                'duration_minutes' => $duration,
                'participants' => max(1, $command->participants),
                'customer_contact_id' => $command->customerContactId,
                'customer_name' => $command->customerName,
                'customer_email' => $command->customerEmail,
                'customer_phone' => $command->customerPhone,
                'meeting_point' => $command->meetingPoint ?? $settings->meeting_point,
                'price_net' => $command->priceNet ?? round($duration / 60 * (float) config('ski_school.lesson_hourly_price'), 2),
                'vat_regime' => $this->settings->vatRegime()->value,
                'status' => $instructor === null ? LessonStatus::REQUESTED->value : LessonStatus::CONFIRMED->value,
                'source' => BookingSource::from($command->source)->value,
                'notes' => $command->notes,
                'created_by' => $command->userId,
            ]);

            if ($command->items !== []) {
                $this->packages->replaceForLesson((string) $lesson->id, $command->items);
            }

            event(new LessonBooked((string) $lesson->id, $instructor?->id !== null ? (string) $instructor->id : null, $command->source));

            return $lesson->refresh();
        });
    }

    /**
     * Assigns the instructor of an open lesson: the given one, or the one the fair rotation proposes.
     *
     * @throws SkiSchoolException lesson_not_found, lesson_not_open, instructor_not_found, instructor_not_qualified, instructor_conflict, requested_instructor_unavailable, no_instructor_available
     */
    public function assign(string $lessonId, ?string $instructorId, ?string $userId = null): SkiLesson
    {
        return DB::transaction(function () use ($lessonId, $instructorId): SkiLesson {
            $lesson = $this->openLesson($lessonId);
            $range = TimeRange::fromTimes($lesson->start_time, $lesson->end_time);
            $date = $lesson->lesson_date->toDateString();

            if ($instructorId !== null) {
                $instructor = $this->lockedInstructor($instructorId);
                $this->assertQualified($instructor, $lesson->discipline->value, $lesson->language_code);
                $this->assertFree($instructor, $date, $range, (string) $lesson->id);
                $method = AssignmentMethod::MANUAL;
            } else {
                $chosen = $this->rotation->suggest($date, $range, $lesson->discipline->value, $lesson->language_code, $lesson->requested_instructor_id, 'week', (string) $lesson->id);
                $instructor = $this->lockedInstructor($chosen);
                $this->assertFree($instructor, $date, $range, (string) $lesson->id);
                $method = $lesson->requested_instructor_id === $chosen ? AssignmentMethod::NOMINATED : AssignmentMethod::FAIR_ROTATION;
            }

            $lesson->update([
                'instructor_id' => (string) $instructor->id,
                'assignment_method' => $method->value,
                'is_nominated' => $lesson->requested_instructor_id !== null && $lesson->requested_instructor_id === (string) $instructor->id,
                'status' => LessonStatus::CONFIRMED->value,
            ]);

            return $lesson->refresh();
        });
    }

    /**
     * Moves an open lesson to another day, time or instructor (drag and drop on the planning board).
     *
     * @param  array<string, mixed>  $data  lesson_date, start_time, slot_minutes, slot_count, instructor_id (all optional)
     *
     * @throws SkiSchoolException lesson_not_found, lesson_not_open, invalid_slot, instructor_not_found, instructor_not_qualified, instructor_conflict
     */
    public function reschedule(string $lessonId, array $data): SkiLesson
    {
        return DB::transaction(function () use ($lessonId, $data): SkiLesson {
            $lesson = $this->openLesson($lessonId);
            $slotMinutes = (int) ($data['slot_minutes'] ?? $lesson->slot_minutes);
            $slotCount = (int) ($data['slot_count'] ?? $lesson->slot_count);
            $this->assertSlot($slotMinutes, $slotCount);
            $range = TimeRange::fromStart((string) ($data['start_time'] ?? $lesson->start_time), $slotMinutes * $slotCount);
            $date = (string) ($data['lesson_date'] ?? $lesson->lesson_date->toDateString());
            $instructorId = array_key_exists('instructor_id', $data) ? ($data['instructor_id'] === null ? null : (string) $data['instructor_id']) : ($lesson->instructor_id === null ? null : (string) $lesson->instructor_id);

            if ($instructorId !== null) {
                $instructor = $this->lockedInstructor($instructorId);
                $this->assertQualified($instructor, $lesson->discipline->value, $lesson->language_code);
                $this->assertFree($instructor, $date, $range, (string) $lesson->id);
            }

            $changedInstructor = $instructorId !== ($lesson->instructor_id === null ? null : (string) $lesson->instructor_id);
            $lesson->update([
                'lesson_date' => $date,
                'start_time' => $range->start(),
                'end_time' => $range->end(),
                'slot_minutes' => $slotMinutes,
                'slot_count' => $slotCount,
                'duration_minutes' => $slotMinutes * $slotCount,
                'instructor_id' => $instructorId,
                'assignment_method' => $changedInstructor ? ($instructorId === null ? AssignmentMethod::PENDING->value : AssignmentMethod::MANUAL->value) : $lesson->assignment_method->value,
                'is_nominated' => $instructorId !== null && $lesson->requested_instructor_id === $instructorId,
                'status' => $instructorId === null ? LessonStatus::REQUESTED->value : LessonStatus::CONFIRMED->value,
            ]);

            return $lesson->refresh();
        });
    }

    /**
     * @throws SkiSchoolException lesson_not_found, lesson_not_open, lesson_invoiced
     */
    public function cancel(string $lessonId, bool $noShow = false): SkiLesson
    {
        return DB::transaction(function () use ($lessonId, $noShow): SkiLesson {
            $lesson = $this->openLesson($lessonId);

            if ($lesson->invoice_id !== null) {
                throw SkiSchoolException::because('lesson_invoiced');
            }

            $lesson->update(['status' => ($noShow ? LessonStatus::NO_SHOW : LessonStatus::CANCELLED)->value]);
            PackageItem::query()->where('lesson_id', $lesson->id)->update(['status' => PackageItemStatus::CANCELLED->value]);

            return $lesson->refresh();
        });
    }

    /**
     * The lesson was taught: the fee of the instructor (rate x time, minus the secretariat retention) is recorded.
     *
     * @throws SkiSchoolException lesson_not_found, lesson_not_open, lesson_without_instructor
     */
    public function complete(string $lessonId): SkiLesson
    {
        return DB::transaction(function () use ($lessonId): SkiLesson {
            $lesson = $this->openLesson($lessonId);

            if ($lesson->instructor_id === null) {
                throw SkiSchoolException::because('lesson_without_instructor');
            }

            $instructor = Instructor::query()->findOrFail($lesson->instructor_id);
            $lesson->update(['status' => LessonStatus::COMPLETED->value, 'completed_at' => now()]);
            $this->commissions->record($instructor, CommissionSource::LESSON, (string) $lesson->id, $lesson->lesson_date->toDateString(), $lesson->duration_minutes);

            event(new LessonCompleted((string) $lesson->id, (string) $instructor->id, $lesson->duration_minutes));

            return $lesson->refresh();
        });
    }

    /**
     * @return array{0: Instructor|null, 1: AssignmentMethod}
     */
    private function resolveInstructor(BookLessonCommand $command, TimeRange $range): array
    {
        if ($command->instructorId !== null) {
            $instructor = $this->lockedInstructor($command->instructorId);
            $this->assertQualified($instructor, $command->discipline, $command->language);
            $this->assertFree($instructor, $command->lessonDate, $range);

            return [$instructor, $command->requestedInstructorId === $command->instructorId ? AssignmentMethod::NOMINATED : AssignmentMethod::MANUAL];
        }

        if ($command->requestedInstructorId !== null || $command->autoAssign) {
            $chosen = $this->rotation->suggest($command->lessonDate, $range, $command->discipline, $command->language, $command->requestedInstructorId);
            $instructor = $this->lockedInstructor($chosen);
            $this->assertFree($instructor, $command->lessonDate, $range);

            return [$instructor, $command->requestedInstructorId === $chosen ? AssignmentMethod::NOMINATED : AssignmentMethod::FAIR_ROTATION];
        }

        return [null, AssignmentMethod::PENDING];
    }

    private function openLesson(string $lessonId): SkiLesson
    {
        $lesson = SkiLesson::query()->whereKey($lessonId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('lesson_not_found', [], 404);

        if (! $lesson->status->isOpen()) {
            throw SkiSchoolException::because('lesson_not_open', ['status' => $lesson->status->value]);
        }

        return $lesson;
    }

    private function lockedInstructor(string $id): Instructor
    {
        $instructor = Instructor::query()->with(['languages', 'disciplines'])->whereKey($id)->lockForUpdate()->first();

        if ($instructor === null || ! $instructor->is_active) {
            throw SkiSchoolException::because('instructor_not_found', [], 404);
        }

        return $instructor;
    }

    private function assertQualified(Instructor $instructor, string $discipline, string $language): void
    {
        if (! in_array($discipline, $instructor->disciplineValues(), true) || ! in_array(strtoupper($language), $instructor->languageCodes(), true)) {
            throw SkiSchoolException::because('instructor_not_qualified', ['instructor' => $instructor->fullName()]);
        }
    }

    private function assertFree(Instructor $instructor, string $date, TimeRange $range, ?string $ignoreLessonId = null): void
    {
        $reason = $this->schedule->conflictFor((string) $instructor->id, $date, $range, $ignoreLessonId);

        if ($reason !== null) {
            throw SkiSchoolException::because('instructor_conflict', ['instructor' => $instructor->fullName(), 'reason' => $reason]);
        }
    }

    private function assertSlot(int $slotMinutes, int $slotCount): void
    {
        if (! in_array($slotMinutes, (array) config('ski_school.slot_minutes'), true) || $slotCount < 1 || $slotCount > (int) config('ski_school.max_slots_per_lesson')) {
            throw SkiSchoolException::because('invalid_slot');
        }
    }
}
