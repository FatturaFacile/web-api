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
    private const array Tables = ['contact' => 'contacts', 'employee' => 'hr_employees', 'lead' => 'crm_leads'];

    /**
     * @return object the row of the subject
     *
     * @throws NotFoundHttpException when the subject is not in the company
     */
    public function resolve(string $tenantId, string $subjectType, string $subjectId): object
    {
        $table = self::Tables[$subjectType] ?? throw new InvalidArgumentException("Unsupported subject_type [{$subjectType}].");

        $row = DB::table($table)->where('tenant_id', $tenantId)->where('id', $subjectId)->first();
        if ($row === null) {
            throw new NotFoundHttpException("The {$subjectType} does not exist in this company.");
        }

        return $row;
    }
}
