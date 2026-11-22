<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Handlers;

use Modules\ResourcePlanning\Application\DTOs\SkillAssessment;
use Modules\ResourcePlanning\Application\Queries\FindBestMatchingResourcesBySkillQuery;
use Modules\ResourcePlanning\Application\Services\AllocationReader;
use Modules\ResourcePlanning\Application\Services\CapacityLoadService;
use Modules\ResourcePlanning\Application\Services\SkillCompliance;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\Enums\BookingType;
use Modules\ResourcePlanning\Domain\ValueObjects\PlannedBooking;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * Candidate suggestions for a project role or WBS node. An employee is eligible when every required skill is held at the
 * minimum proficiency and is valid through the window, the certificates of the skills that need one (Mod 099) are valid, and
 * the net residual availability (net hours minus what is already booked, tentative bookings included) covers the hours per
 * day. The score is 60 % skill proficiency and 40 % availability.
 */
final class FindBestMatchingResourcesBySkillHandler
{
    public function __construct(
        private readonly ResourceDirectoryPort $directory,
        private readonly SkillCompliance $skills,
        private readonly CapacityLoadService $load,
        private readonly AllocationReader $reader,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(FindBestMatchingResourcesBySkillQuery $query): array
    {
        $start = $query->startDate->startOfDay();
        $end = $query->endDate->startOfDay();
        $profiles = $this->directory->resources(null, $query->departmentId, $start);

        if ($profiles === []) {
            return [];
        }

        $ids = array_map(static fn (ResourceProfile $profile): string => $profile->employeeId, $profiles);
        $assessments = $this->skills->assess($ids, $query->requirements, $start, $end, $query->requireValidCertificates);
        $bookings = $this->reader->bookings($ids, $start, $end);
        $daily = $this->load->grid($profiles, $bookings, $start, $end, false);
        $extras = array_map(fn (ResourceProfile $profile): PlannedBooking => new PlannedBooking($profile->employeeId, $start, $end, $query->hoursPerDay, BookingType::SoftTentative, true), $profiles);
        $weekly = $this->load->grid($profiles, [...$bookings, ...$extras], $start, $end, true);

        $candidates = [];

        foreach ($profiles as $profile) {
            $assessment = $assessments[$profile->employeeId] ?? new SkillAssessment([], [], 0.0);
            $available = 0.0;
            $workable = 0;

            foreach ($daily[$profile->employeeId] ?? [] as $cell) {
                if ($cell->netHours > 0.0) {
                    $workable++;
                    $available += max(0.0, $cell->netHours - $cell->bookedHours());
                }
            }

            $perDay = $workable === 0 ? 0.0 : $available / $workable;
            $requested = $workable * $query->hoursPerDay;
            $blockers = $assessment->blockers;

            if ($perDay + 0.005 < $query->hoursPerDay) {
                $blockers[] = ['code' => 'insufficient_availability', 'skill_id' => null, 'params' => ['available_hours' => round($available, 2), 'requested_hours' => round($requested, 2)]];
            }

            $eligible = $blockers === [];

            if (! $eligible && ! $query->includeIneligible) {
                continue;
            }

            $skillScore = $query->requirements === [] ? 1.0 : $assessment->levelRatio;
            $availability = $query->hoursPerDay <= 0.0 ? 1.0 : min(1.0, $perDay / $query->hoursPerDay);
            $peak = 0.0;

            foreach ($weekly[$profile->employeeId] ?? [] as $cell) {
                $peak = max($peak, $cell->loadPercent());
            }

            $candidates[] = [
                'employee_id' => $profile->employeeId,
                'employee_name' => $profile->fullName,
                'department_name' => $profile->departmentName,
                'score' => (int) round(60 * $skillScore + 40 * $availability),
                'eligible' => $eligible,
                'matched_skills' => $assessment->matched,
                'blockers' => $blockers,
                'available_hours' => round($available, 2),
                'requested_hours' => round($requested, 2),
                'residual_hours_per_day' => round($perDay, 2),
                'peak_load_after_percent' => $peak,
                'hourly_cost' => round($profile->hourlyCost, 2),
            ];
        }

        usort($candidates, static fn (array $left, array $right): int => [$right['eligible'], $right['score'], $right['available_hours'], $left['employee_name']]
            <=> [$left['eligible'], $left['score'], $left['available_hours'], $right['employee_name']]);

        return array_slice($candidates, 0, max(1, $query->limit));
    }
}
