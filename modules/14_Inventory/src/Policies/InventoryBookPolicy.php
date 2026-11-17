<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\InventoryBookExport;
use Modules\Inventory\Services\InventoryPermissionChecker;

final class InventoryBookPolicy
{
    public function __construct(
        private readonly InventoryPermissionChecker $permissions
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->isMember($user);
    }

    public function view(User $user, InventoryBookExport $export): bool
    {
        return (string) $export->tenant_id === (string) $this->permissions->currentTenantId()
            && $this->permissions->isMember($user);
    }

    public function export(User $user): bool
    {
        return $this->permissions->allows($user, InventoryPermissionChecker::BOOK_EXPORT);
    }

    public function download(User $user, InventoryBookExport $export): bool
    {
        return $this->view($user, $export) && $this->export($user);
    }
}
