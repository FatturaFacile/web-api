<?php

declare(strict_types=1);

namespace Modules\Payroll\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\HumanResources\Models\Employee;

/**
 * Payslips and salary certificates: whoever can read the payroll sees all of them, an employee only the own ones.
 */
final class EmployeeDocumentPolicy extends PayrollPolicy
{
    /**
     * Every member of the company can open the list: it is filtered down to the own documents.
     */
    public function viewAny(User $user): bool
    {
        return $this->tenantContext->getTenantId() !== null;
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isOwn($record)
            && ($this->canRead($user) || in_array((string) $record->getAttribute('employee_id'), $this->ownEmployeeIds($user), true));
    }

    /**
     * The documents of one employee (`employee_id` filter).
     */
    public function viewEmployee(User $user, string $employeeId): bool
    {
        return $this->canRead($user) || in_array($employeeId, $this->ownEmployeeIds($user), true);
    }

    /**
     * Whether the user sees the documents of every employee of the company.
     */
    public function viewAllEmployees(User $user): bool
    {
        return $this->canRead($user);
    }

    /**
     * The employee records of the user in the active company.
     *
     * @return list<string>
     */
    public function ownEmployeeIds(User $user): array
    {
        return Employee::query()->withTrashed()->where('user_id', $user->id)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
    }
}
