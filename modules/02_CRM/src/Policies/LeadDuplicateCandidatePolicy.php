<?php

declare(strict_types=1);

namespace Modules\CRM\Policies;

use App\Models\User;
use Modules\CRM\Models\LeadDuplicateCandidate;

/**
 * Any member of the tenant may review, merge or dismiss duplicate candidates.
 */
final class LeadDuplicateCandidatePolicy
{
    use ResolvesCrmTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->isTenantMember($user);
    }

    public function view(User $user, LeadDuplicateCandidate $candidate): bool
    {
        return $this->isTenantMember($user);
    }

    public function update(User $user, LeadDuplicateCandidate $candidate): bool
    {
        return $this->isTenantMember($user);
    }
}
