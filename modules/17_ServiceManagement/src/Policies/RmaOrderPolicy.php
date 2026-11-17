<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;

final class RmaOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Goodwill (out-of-warranty repair charged to the warranty provision). Any tenant service user may grant it
     * until a dedicated permission exists; the decision is recorded on the RMA history and audit ledger.
     */
    public function grantGoodwill(User $user): bool
    {
        return true;
    }

    public function view(User $user, DepotRepairRmaOrder $rmaOrder): bool
    {
        return $this->belongsToTenant($user, (string) $rmaOrder->tenant_id);
    }

    public function update(User $user, DepotRepairRmaOrder $rmaOrder): bool
    {
        return $this->belongsToTenant($user, (string) $rmaOrder->tenant_id);
    }

    public function transition(User $user, DepotRepairRmaOrder $rmaOrder): bool
    {
        return $this->belongsToTenant($user, (string) $rmaOrder->tenant_id);
    }

    public function issueLoaner(User $user, DepotRepairRmaOrder $rmaOrder): bool
    {
        return $this->belongsToTenant($user, (string) $rmaOrder->tenant_id);
    }

    public function delete(User $user, DepotRepairRmaOrder $rmaOrder): bool
    {
        return $this->belongsToTenant($user, (string) $rmaOrder->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
