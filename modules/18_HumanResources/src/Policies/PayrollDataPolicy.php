<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization of the other Swiss payroll records: payroll profiles, payslips, salary declarations, rate
 * sets, wage types and withholding tax tariffs. System defaults (null tenant) are readable but never editable
 * through the API.
 */
final class PayrollDataPolicy
{
    use ChecksPayrollPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewPayroll($user);
    }

    public function view(User $user, Model $record): bool
    {
        $tenantId = $this->tenantOf($record);

        return $this->isInActiveTenant($tenantId, allowSystemRecord: true) && $this->canViewPayroll($user, $tenantId);
    }

    public function create(User $user): bool
    {
        return $this->canManagePayroll($user);
    }

    public function update(User $user, Model $record): bool
    {
        $tenantId = $this->tenantOf($record);

        return $this->isInActiveTenant($tenantId) && $this->canManagePayroll($user, $tenantId);
    }

    private function tenantOf(Model $record): ?string
    {
        $tenantId = $record->getAttribute('tenant_id');

        return $tenantId === null ? null : (string) $tenantId;
    }
}
