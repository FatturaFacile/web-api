<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Services;

use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\InstructorCandidate;

/**
 * Fair rotation / load balancing: ranks the instructors who can take a lesson (discipline taught, language spoken, slot free) by the lowest
 * cumulative assigned hours in the period. Ties are broken by who has waited longest since the last assignment, then by name. A nominated
 * ("requested") instructor overrides the rotation as long as he or she can take the lesson. Pure: no database access.
 *
 * @phpstan-type Ranked array{instructor_id: string, name: string, rank: int|null, eligible: bool, is_requested: bool, hours_in_period: float, reasons: list<string>}
 */
final class FairRotationService
{
    /**
     * @param  list<InstructorCandidate>  $candidates
     * @return list<Ranked> eligible instructors first (rank 1..n), the others after them with the reasons why they cannot
     */
    public function rank(array $candidates, string $discipline, string $language, ?string $requestedInstructorId = null): array
    {
        $language = strtoupper($language);
        $eligible = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            $reasons = [];

            if (! in_array($discipline, $candidate->disciplines, true)) {
                $reasons[] = 'discipline_mismatch';
            }

            if (! in_array($language, $candidate->languages, true)) {
                $reasons[] = 'language_mismatch';
            }

            if (! $candidate->available) {
                $reasons[] = $candidate->unavailableReason ?? 'unavailable';
            }

            if ($reasons === []) {
                $eligible[] = $candidate;
            } else {
                $rejected[] = [$candidate, $reasons];
            }
        }

        usort($eligible, static function (InstructorCandidate $a, InstructorCandidate $b) use ($requestedInstructorId): int {
            $aRequested = $requestedInstructorId !== null && $a->id === $requestedInstructorId;
            $bRequested = $requestedInstructorId !== null && $b->id === $requestedInstructorId;

            if ($aRequested !== $bRequested) {
                return $aRequested ? -1 : 1;
            }

            return [round($a->hoursInPeriod, 4), $a->lastAssignedAt ?? '', $a->name, $a->id]
                <=> [round($b->hoursInPeriod, 4), $b->lastAssignedAt ?? '', $b->name, $b->id];
        });

        $ranked = [];

        foreach ($eligible as $index => $candidate) {
            $ranked[] = $this->row($candidate, $index + 1, true, $requestedInstructorId, []);
        }

        usort($rejected, static fn (array $a, array $b): int => [$a[0]->name, $a[0]->id] <=> [$b[0]->name, $b[0]->id]);

        foreach ($rejected as [$candidate, $reasons]) {
            $ranked[] = $this->row($candidate, null, false, $requestedInstructorId, $reasons);
        }

        return $ranked;
    }

    /**
     * The instructor the rotation proposes: the requested one when he or she is eligible, otherwise the least loaded.
     *
     * @param  list<InstructorCandidate>  $candidates
     *
     * @throws SkiSchoolException requested_instructor_unavailable, no_instructor_available
     */
    public function suggest(array $candidates, string $discipline, string $language, ?string $requestedInstructorId = null): string
    {
        $ranked = $this->rank($candidates, $discipline, $language, $requestedInstructorId);

        if ($requestedInstructorId !== null) {
            foreach ($ranked as $row) {
                if ($row['instructor_id'] === $requestedInstructorId && ! $row['eligible']) {
                    throw SkiSchoolException::because('requested_instructor_unavailable', ['reasons' => implode(',', $row['reasons'])]);
                }
            }
        }

        if ($ranked === [] || ! $ranked[0]['eligible']) {
            throw SkiSchoolException::because('no_instructor_available');
        }

        return $ranked[0]['instructor_id'];
    }

    /**
     * @param  list<string>  $reasons
     * @return Ranked
     */
    private function row(InstructorCandidate $candidate, ?int $rank, bool $eligible, ?string $requestedId, array $reasons): array
    {
        return [
            'instructor_id' => $candidate->id,
            'name' => $candidate->name,
            'rank' => $rank,
            'eligible' => $eligible,
            'is_requested' => $requestedId !== null && $candidate->id === $requestedId,
            'hours_in_period' => round($candidate->hoursInPeriod, 2),
            'reasons' => $reasons,
        ];
    }
}
