<?php

declare(strict_types=1);

namespace Modules\Academy\Commands;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academy\Models\ClassroomAssignment;
use Modules\Academy\Models\ClassroomCohort;

/**
 * Creates a classroom cohort led by the caller, with a join code for the students and the tracks assigned to it.
 */
final readonly class CreateClassroomCohortCommand
{
    /**
     * @param  list<string>  $trackIds
     */
    public function execute(User $teacher, string $tenantId, string $name, array $trackIds = [], ?string $dueDate = null): ClassroomCohort
    {
        return DB::transaction(function () use ($teacher, $tenantId, $name, $trackIds, $dueDate): ClassroomCohort {
            $cohort = ClassroomCohort::query()->create([
                'tenant_id' => $tenantId,
                'teacher_id' => (string) $teacher->id,
                'name' => $name,
                'join_code' => $this->uniqueJoinCode(),
            ]);

            foreach (array_unique($trackIds) as $trackId) {
                ClassroomAssignment::query()->create([
                    'tenant_id' => $tenantId,
                    'cohort_id' => $cohort->id,
                    'track_id' => $trackId,
                    'due_date' => $dueDate,
                ]);
            }

            return $cohort;
        });
    }

    private function uniqueJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (ClassroomCohort::withoutGlobalScopes()->where('join_code', $code)->exists());

        return $code;
    }
}
