<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Modules\HumanResources\Models\Payroll\PayrollRun;

/**
 * Authorization of Swiss payroll runs. State rules (closed runs are immutable) are enforced by the run
 * service and answered with 409, not here.
 */
final class PayrollRunPolicy
{
    use ChecksPayrollPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewPayroll($user);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $this->isInActiveTenant((string) $run->tenant_id) && $this->canViewPayroll($user, (string) $run->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->canManagePayroll($user);
    }

    public function update(User $user, PayrollRun $run): bool
    {
        return $this->manages($user, $run);
    }

    public function calculate(User $user, PayrollRun $run): bool
    {
        return $this->manages($user, $run);
    }

    public function approve(User $user, PayrollRun $run): bool
    {
        return $this->manages($user, $run);
    }

    public function close(User $user, PayrollRun $run): bool
    {
        return $this->manages($user, $run);
    }

    private function manages(User $user, PayrollRun $run): bool
    {
        return $this->isInActiveTenant((string) $run->tenant_id) && $this->canManagePayroll($user, (string) $run->tenant_id);
    }
}
