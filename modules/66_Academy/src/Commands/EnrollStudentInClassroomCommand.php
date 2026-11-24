<?php

declare(strict_types=1);

namespace Modules\Academy\Commands;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Academy\Models\ClassroomCohort;
use Modules\Academy\Models\ClassroomEnrollment;

/**
 * Enrolls the caller in a cohort of their tenant by join code. Joining twice is harmless.
 */
final readonly class EnrollStudentInClassroomCommand
{
    /**
     * @throws ModelNotFoundException
     */
    public function execute(User $student, string $tenantId, string $joinCode): ClassroomEnrollment
    {
        $cohort = ClassroomCohort::query()
            ->where('tenant_id', $tenantId)
            ->where('join_code', strtoupper(trim($joinCode)))
            ->firstOrFail();

        return ClassroomEnrollment::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'cohort_id' => $cohort->id, 'user_id' => (string) $student->id],
            ['enrolled_at' => now()],
        );
    }
}
