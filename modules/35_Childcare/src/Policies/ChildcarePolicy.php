<?php

declare(strict_types=1);

namespace Modules\Childcare\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Children's data is sensitive, so each area of the module has its own roles on top of the module access:
 * - care (attendance, notes, meals, plans, messages): the educators and the management;
 * - records (child master data, reference persons, documents, subsidy rules, corrections, exports): the management;
 * - billing (fee plans, monthly bills): the management and the accountant, who sees no care data.
 * Records of another company are never visible.
 */
final class ChildcarePolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    private const array CareRoles = ['owner', 'admin', 'childcare_manager', 'educator'];

    /** @var list<string> */
    private const array RecordRoles = ['owner', 'admin', 'childcare_manager'];

    /** @var list<string> */
    private const array BillingRoles = ['owner', 'admin', 'childcare_manager', 'accountant'];

    public function care(User $user, ?Model $record = null): bool
    {
        return $this->allows($user, self::CareRoles, 'childcare.care', $record);
    }

    public function manageRecords(User $user, ?Model $record = null): bool
    {
        return $this->allows($user, self::RecordRoles, 'childcare.records', $record);
    }

    public function correct(User $user, ?Model $record = null): bool
    {
        return $this->allows($user, self::RecordRoles, 'childcare.records', $record);
    }

    public function bill(User $user, ?Model $record = null): bool
    {
        return $this->allows($user, self::BillingRoles, 'childcare.billing', $record);
    }

    /**
     * @param  list<string>  $roles
     */
    private function allows(User $user, array $roles, string $permission, ?Model $record): bool
    {
        return $this->hasRoleOrPermission($user, $roles, [$permission]) && ($record === null || $this->isOwnRecord($record));
    }
}
