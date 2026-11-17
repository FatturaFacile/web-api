<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Services\InventoryPermissionChecker;

final class LandedCostDocumentPolicy
{
    public function __construct(
        private readonly InventoryPermissionChecker $permissions
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->isMember($user);
    }

    public function view(User $user, LandedCostDocument $document): bool
    {
        return $this->sameTenant($document) && $this->permissions->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, InventoryPermissionChecker::VALUATION_MANAGE);
    }

    public function apply(User $user, LandedCostDocument $document): bool
    {
        if (! $this->sameTenant($document) || ! $this->permissions->allows($user, InventoryPermissionChecker::VALUATION_MANAGE)) {
            return false;
        }

        // Segregation of Duties (when configured): the applier must differ from the creator.
        if ((bool) config('inventory.valuation.segregation_of_duties', false)) {
            return $document->created_by === null || (string) $document->created_by !== (string) $user->id;
        }

        return true;
    }

    public function reverse(User $user, LandedCostDocument $document): bool
    {
        return $this->sameTenant($document) && $this->permissions->allows($user, InventoryPermissionChecker::VALUATION_MANAGE);
    }

    private function sameTenant(LandedCostDocument $document): bool
    {
        return (string) $document->tenant_id === (string) $this->permissions->currentTenantId();
    }
}
