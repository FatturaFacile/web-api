<?php

declare(strict_types=1);

namespace Modules\CRM\Policies;

use App\Models\User;
use Modules\CRM\Models\LeadRoutingRule;

final class LeadRoutingRulePolicy
{
    use ResolvesCrmTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->isTenantMember($user);
    }

    public function view(User $user, LeadRoutingRule $model): bool
    {
        return $this->isTenantMember($user);
    }

    public function create(User $user): bool
    {
        return $this->isLeadManager($user);
    }

    public function update(User $user, LeadRoutingRule $model): bool
    {
        return $this->isLeadManager($user);
    }

    public function delete(User $user, LeadRoutingRule $model): bool
    {
        return $this->isLeadManager($user);
    }
}
