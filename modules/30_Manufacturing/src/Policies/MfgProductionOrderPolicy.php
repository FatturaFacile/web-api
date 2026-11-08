<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Policies;

use App\Models\User;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Policies\Concerns\ChecksManufacturingRoles;

/**
 * Record-level rules of production orders: the order must belong to the active company; closing (the variance
 * settlement in the ledger) needs a manufacturing manager.
 */
final class MfgProductionOrderPolicy
{
    use ChecksManufacturingRoles;

    /**
     * The order belongs to the active company.
     */
    public function view(?User $user, MfgProductionOrder $order): bool
    {
        return $this->ownsRecord($user, $order);
    }

    /**
     * Execution (release, issue, confirm, receive, complete, cancel) on an order of the company.
     */
    public function execute(?User $user, MfgProductionOrder $order): bool
    {
        return $this->ownsRecord($user, $order) && ! $order->is_locked;
    }

    /**
     * Closing posts the variances: a manufacturing manager of the company.
     */
    public function close(?User $user, MfgProductionOrder $order): bool
    {
        return $this->managesRecord($user, $order) && ! $order->is_locked;
    }
}
