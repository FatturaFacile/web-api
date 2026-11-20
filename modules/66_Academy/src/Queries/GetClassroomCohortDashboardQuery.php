<?php

declare(strict_types=1);

namespace Modules\Academy\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Academy\Models\AcademyCertificate;
use Modules\Academy\Models\ClassroomCohort;
use Modules\Academy\Models\InteractiveMission;
use Modules\Academy\Models\UserAcademyProgress;

/**
 * The teacher's view of a cohort: the assigned tracks and, per student, completed missions, XP and certificates.
 */
final class GetClassroomCohortDashboardQuery
{
    /**
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException
     */
    public function execute(string $tenantId, string $cohortId): array
    {
        $cohort = ClassroomCohort::query()
            ->where('tenant_id', $tenantId)
            ->with(['assignments.track', 'enrollments'])
            ->findOrFail($cohortId);

        $trackIds = $cohort->assignments->pluck('track_id')->all();
        $studentIds = $cohort->enrollments->pluck('user_id')->all();
        $missionTotal = InteractiveMission::query()->whereIn('track_id', $trackIds)->count();

        $progress = UserAcademyProgress::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $studentIds)
            ->whereIn('track_id', $trackIds)
            ->get()
            ->groupBy('user_id');

        $certificates = AcademyCertificate::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $studentIds)
            ->whereIn('track_id', $trackIds)
            ->get()
            ->groupBy('user_id');

        $names = User::query()->whereIn('id', $studentIds)->pluck('name', 'id');

        $students = [];

        foreach ($cohort->enrollments as $enrollment) {
            $rows = $progress->get($enrollment->user_id, collect());
            $students[] = [
                'user_id' => (string) $enrollment->user_id,
                'name' => (string) ($names[$enrollment->user_id] ?? ''),
                'enrolled_at' => $enrollment->enrolled_at->toIso8601String(),
                'missions_started' => $rows->count(),
                'missions_completed' => $rows->where('status', UserAcademyProgress::Completed)->count(),
                'missions_total' => $missionTotal,
                'total_xp' => (int) $rows->sum('xp_awarded'),
                'certificates' => $certificates->get($enrollment->user_id, collect())->pluck('certificate_code')->values()->all(),
            ];
        }

        return [
            'cohort' => ['id' => (string) $cohort->id, 'name' => $cohort->name, 'join_code' => $cohort->join_code, 'teacher_id' => $cohort->teacher_id],
            'assignments' => $cohort->assignments->map(static fn ($assignment): array => [
                'track_id' => (string) $assignment->track_id,
                'track_code' => $assignment->track?->code,
                'due_date' => $assignment->due_date?->toDateString(),
            ])->values()->all(),
            'students' => $students,
        ];
    }
}
