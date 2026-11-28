<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Services;

use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\Services\FairRotationService;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\InstructorCandidate;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;
use Modules\SkiOutdoorSchool\Models\Instructor;

/**
 * Loads the active instructors with what they teach and speak, whether the slot is free and their load in the rotation period, and hands
 * them to the pure fair rotation service. The single place that decides who may take a lesson.
 */
final class RotationCandidateBuilder
{
    public function __construct(private readonly InstructorScheduleService $schedule, private readonly FairRotationService $rotation) {}

    /**
     * @return list<InstructorCandidate>
     */
    public function candidates(string $date, TimeRange $range, string $period = 'week', ?string $ignoreLessonId = null): array
    {
        $instructors = Instructor::query()->with(['languages', 'disciplines'])->where('is_active', true)->orderBy('last_name')->orderBy('first_name')->get();
        $ids = $instructors->map(static fn (Instructor $instructor): string => (string) $instructor->id)->all();
        $loads = $this->schedule->dayLoads($date, $ids);
        [$from, $to] = $this->schedule->period($date, $period);
        $hours = $this->schedule->load($ids, $from, $to);
        $candidates = [];

        foreach ($instructors as $instructor) {
            $id = (string) $instructor->id;
            $conflict = $this->schedule->conflict($loads[$id], $range, $ignoreLessonId);
            $candidates[] = new InstructorCandidate(
                id: $id,
                name: $instructor->fullName(),
                disciplines: $instructor->disciplineValues(),
                languages: $instructor->languageCodes(),
                available: $conflict === null,
                hoursInPeriod: $hours[$id]['hours'],
                lastAssignedAt: $hours[$id]['last_assigned_at'],
                unavailableReason: $conflict,
            );
        }

        return $candidates;
    }

    /**
     * @return list<array{instructor_id: string, name: string, rank: int|null, eligible: bool, is_requested: bool, hours_in_period: float, reasons: list<string>}>
     */
    public function ranking(string $date, TimeRange $range, string $discipline, string $language, ?string $requestedInstructorId = null, string $period = 'week', ?string $ignoreLessonId = null): array
    {
        return $this->rotation->rank($this->candidates($date, $range, $period, $ignoreLessonId), $discipline, $language, $requestedInstructorId);
    }

    /**
     * @throws SkiSchoolException requested_instructor_unavailable, no_instructor_available
     */
    public function suggest(string $date, TimeRange $range, string $discipline, string $language, ?string $requestedInstructorId = null, string $period = 'week', ?string $ignoreLessonId = null): string
    {
        return $this->rotation->suggest($this->candidates($date, $range, $period, $ignoreLessonId), $discipline, $language, $requestedInstructorId);
    }
}
