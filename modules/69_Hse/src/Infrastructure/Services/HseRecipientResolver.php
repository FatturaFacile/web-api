<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use App\Models\Tenant;
use Modules\HumanResources\Models\Employee;

/**
 * Who hears about an HSE alert: the HR managers, the RSPP (IT) / SiBe (CH) and the line manager of the employee.
 */
final class HseRecipientResolver
{
    /** Roles in the tenant membership: "hr" for the HR manager, "hse" for the RSPP / SiBe. */
    public const array Roles = ['hr', 'hse'];

    /**
     * @return list<string> user ids
     */
    public function forEmployee(string $tenantId, ?string $employeeId): array
    {
        $ids = Tenant::query()->find($tenantId)?->users()->wherePivotIn('role', self::Roles)->pluck('users.id')->map(fn (mixed $id): string => (string) $id)->all() ?? [];

        $employee = $employeeId === null ? null : Employee::query()->where('tenant_id', $tenantId)->whereKey($employeeId)->first();

        if ($employee?->manager_id !== null) {
            $managerUser = Employee::query()->where('tenant_id', $tenantId)->whereKey($employee->manager_id)->value('user_id');

            if (is_string($managerUser) && $managerUser !== '') {
                $ids[] = $managerUser;
            }
        }

        return array_values(array_unique($ids));
    }
}
