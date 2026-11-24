<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checks that a data subject exists in the company. Soft deleted subjects still exist: their data are still held.
 */
final class SubjectResolver
{
    private const array Tables = [
        'contact' => 'contacts',
        'employee' => 'hr_employees',
        'lead' => 'crm_leads',
        'member' => 'assoc_members',
        'child' => 'cc_children',
        'visitor' => 'visitors',
        'applicant' => 'hr_job_applications',
    ];

    /**
     * @return object the row of the subject
     *
     * @throws NotFoundHttpException when the subject is not in the company
     */
    public function resolve(string $tenantId, string $subjectType, string $subjectId): object
    {
        if ($subjectType === 'user') {
            $user = DB::table('users')
                ->join('tenant_user', 'tenant_user.user_id', '=', 'users.id')
                ->where('tenant_user.tenant_id', $tenantId)
                ->where('users.id', $subjectId)
                ->first(['users.*']);

            return $user ?? throw new NotFoundHttpException('The user is not a member of this company.');
        }

        $table = self::Tables[$subjectType] ?? throw new InvalidArgumentException("Unsupported subject_type [{$subjectType}].");

        $row = DB::table($table)->where('tenant_id', $tenantId)->where('id', $subjectId)->first();
        if ($row === null) {
            throw new NotFoundHttpException("The {$subjectType} does not exist in this company.");
        }

        return $row;
    }
}
