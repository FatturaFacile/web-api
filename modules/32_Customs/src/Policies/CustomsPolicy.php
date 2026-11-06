<?php

declare(strict_types=1);

namespace Modules\Customs\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\Customs\Models\CustomsRecord;

/**
 * Access to the records of the active company. The module.access middleware decides who reads and writes the module;
 * the policy keeps a request inside its company, makes generic rows read-only and reserves the ledger to finance.
 */
class CustomsPolicy
{
    /** @var list<string> */
    private const array FinanceRoles = ['owner', 'admin', 'accountant'];

    /** @var list<string> */
    private const array FinancePermissions = ['*', 'gl.*', 'customs.ledger'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isVisible($record);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwn($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwn($record);
    }

    /**
     * Posting to the ledger is an accounting act, whatever the access to the module.
     */
    public function post(User $user, Model $record): bool
    {
        return $this->isOwn($record) && $this->isFinance($user);
    }

    public function reconcile(User $user): bool
    {
        return $this->isFinance($user);
    }

    private function isMember(User $user): bool
    {
        return $this->pivotOf($user) !== null;
    }

    private function isFinance(User $user): bool
    {
        $pivot = $this->pivotOf($user);

        if ($pivot === null) {
            return false;
        }

        return in_array($pivot->getAttribute('role'), self::FinanceRoles, true)
            || array_intersect(self::FinancePermissions, $this->decodePermissions($pivot->getAttribute('permissions'))) !== [];
    }

    private function pivotOf(User $user): ?object
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId === null ? null : $user->tenants()->where('tenants.id', $tenantId)->first()?->pivot;
    }

    private function isOwn(Model $record): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && (string) $record->getAttribute('tenant_id') === (string) $tenantId;
    }

    private function isVisible(Model $record): bool
    {
        return ($record instanceof CustomsRecord && $record->isGeneric()) || $this->isOwn($record);
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
