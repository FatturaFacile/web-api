<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Modules\HumanResources\Models\CandidateApplication;

/**
 * Tenant membership checks for candidate applications. Anonymised applications are not denied here: the actions
 * answer `409 candidate_anonymized` as required by the REST contract.
 */
final class CandidateApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CandidateApplication $application): bool
    {
        return $this->belongsToTenant($user, (string) $application->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CandidateApplication $application): bool
    {
        return $this->belongsToTenant($user, (string) $application->tenant_id);
    }

    /**
     * Any member of the tenant may submit (and edit) their own scorecard.
     */
    public function score(User $user, CandidateApplication $application): bool
    {
        return $this->belongsToTenant($user, (string) $application->tenant_id);
    }

    public function hire(User $user, CandidateApplication $application): bool
    {
        return $this->belongsToTenant($user, (string) $application->tenant_id);
    }

    public function anonymize(User $user, CandidateApplication $application): bool
    {
        return $this->belongsToTenant($user, (string) $application->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
