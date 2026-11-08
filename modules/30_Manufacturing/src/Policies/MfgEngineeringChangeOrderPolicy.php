<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies;

use App\Models\User;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Policies\Concerns\ChecksManufacturingRoles;

/**
 * Record-level rules of engineering changes: any writer submits, a manufacturing manager other than the requester
 * approves or rejects, a manager implements.
 */
final class MfgEngineeringChangeOrderPolicy
{
    use ChecksManufacturingRoles;

    /**
     * The change belongs to the active company.
     */
    public function view(?User $user, MfgEngineeringChangeOrder $eco): bool
    {
        return $this->ownsRecord($user, $eco);
    }

    /**
     * Submitting a change of the company.
     */
    public function submit(?User $user, MfgEngineeringChangeOrder $eco): bool
    {
        return $this->ownsRecord($user, $eco);
    }

    /**
     * Approving or rejecting: a manager who did not request the change (segregation of duties).
     */
    public function decide(?User $user, MfgEngineeringChangeOrder $eco): bool
    {
        return $this->managesRecord($user, $eco) && (string) $eco->requested_by !== (string) $user?->getAuthIdentifier();
    }

    /**
     * Implementing an approved change: a manager.
     */
    public function implement(?User $user, MfgEngineeringChangeOrder $eco): bool
    {
        return $this->managesRecord($user, $eco);
    }
}
