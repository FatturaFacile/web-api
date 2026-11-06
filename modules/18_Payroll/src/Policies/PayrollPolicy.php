<?php

declare(strict_types=1);

namespace Modules\Payroll\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\Payroll\Models\PayrollRecord;

/**
 * Access to the payroll of the active company: owners and administrators, or the users whose tenant_user pivot grants
 * payroll.read or payroll.write. Roles and permissions are those of the active tenant, never those the user holds in
 * another company; a record of another company is never accessible, and generic rows can be read but not changed.
 */
class PayrollPolicy
{
    /** @var list<string> */
    private const array ManagerRoles = ['owner', 'admin'];

    /** @var list<string> */
    private const array WritePermissions = ['*', 'payroll.*', 'payroll.write'];

    public function __construct(protected readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->canRead($user) && $this->isVisible($record);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->canWrite($user) && $this->isOwn($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->canWrite($user) && $this->isOwn($record);
    }

    /**
     * Saves nothing: the same access as reading.
     */
    public function simulate(User $user): bool
    {
        return $this->canRead($user);
    }

    public function calculate(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function finalize(User $user, Model $run): bool
    {
        return $this->canWrite($user) && $this->isOwn($run);
    }

    public function reopen(User $user, Model $run): bool
    {
        return $this->canWrite($user) && $this->isOwn($run);
    }

    public function export(User $user): bool
    {
        return $this->canRead($user);
    }

    protected function canRead(User $user): bool
    {
        $permissions = $this->payrollPermissions($user);

        return $permissions['manager'] || $permissions['write'] || in_array('payroll.read', $permissions['granted'], true);
    }

    protected function canWrite(User $user): bool
    {
        $permissions = $this->payrollPermissions($user);

        return $permissions['manager'] || $permissions['write'];
    }

    protected function isOwn(Model $record): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && (string) $record->getAttribute('tenant_id') === (string) $tenantId;
    }

    protected function isVisible(Model $record): bool
    {
        return ($record instanceof PayrollRecord && $record->isGeneric()) || $this->isOwn($record);
    }

    /**
     * @return array{manager: bool, write: bool, granted: list<string>}
     */
    private function payrollPermissions(User $user): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $pivot = $tenantId === null ? null : $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;

        if ($pivot === null) {
            return ['manager' => false, 'write' => false, 'granted' => []];
        }

        $granted = $this->decodePermissions($pivot->getAttribute('permissions'));

        return [
            'manager' => in_array($pivot->getAttribute('role'), self::ManagerRoles, true),
            'write' => array_intersect(self::WritePermissions, $granted) !== [],
            'granted' => $granted,
        ];
    }

    /**
     * The pivot is not cast, and some writers encode the JSON twice.
     *
     * @return list<string>
     */
    private function decodePermissions(mixed $permissions): array
    {
        while (is_string($permissions)) {
            $decoded = json_decode($permissions, true);

            if ($decoded === null) {
                return [];
            }

            $permissions = $decoded;
        }

        return is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [];
    }
}
