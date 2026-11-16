<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Contracts;

use Modules\TalentLearning\Support\DirectoryEmployee;
use Modules\TalentLearning\Support\DirectoryPosition;

/**
 * What talent and learning reads from human resources. The module never queries the HR tables directly: employees,
 * positions and the base salary come through this contract, always scoped to one company.
 */
interface HrDirectory
{
    public function employee(string $tenantId, string $employeeId): ?DirectoryEmployee;

    /**
     * The employee record linked to a user login, if there is one.
     */
    public function employeeOfUser(string $tenantId, string $userId): ?DirectoryEmployee;

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, DirectoryEmployee> keyed by employee id
     */
    public function employeesByIds(string $tenantId, array $employeeIds): array;

    /**
     * Employees whose employment is running, optionally narrowed by a name or number search.
     *
     * @return list<DirectoryEmployee>
     */
    public function activeEmployees(string $tenantId, ?string $search = null, ?int $limit = null): array;

    public function position(string $tenantId, string $positionId): ?DirectoryPosition;

    /**
     * @return list<DirectoryPosition>
     */
    public function positions(string $tenantId, ?string $search = null, int $limit = 25): array;

    /**
     * The base annual salary of an employee as [amount, currency], or null when the employee does not exist.
     *
     * @return array{amount: string, currency: string}|null
     */
    public function baseAnnualSalary(string $tenantId, string $employeeId): ?array;

    /**
     * Whether the department exists in the company.
     */
    public function departmentExists(string $tenantId, string $departmentId): bool;
}
