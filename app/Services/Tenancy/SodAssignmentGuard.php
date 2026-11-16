<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\SodConflictException;

/**
 * Reviews a membership change against the segregation of duties rules. Implemented by the governance module and bound
 * only when it is installed: without a binding every assignment is allowed.
 */
interface SodAssignmentGuard
{
    /**
     * Refuses the assignment when it breaks a blocking rule that no active exception covers, and keeps the register of
     * violations of the member in line with the assignment.
     *
     * @param  list<string>  $permissions
     *
     * @throws SodConflictException
     */
    public function review(string $tenantId, string $userId, string $role, array $permissions): void;
}
