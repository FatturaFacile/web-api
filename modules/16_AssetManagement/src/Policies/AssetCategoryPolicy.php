<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Policies;

use App\Models\User;
use Modules\AssetManagement\Models\AssetCategory;

final class AssetCategoryPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, AssetCategory $category): bool
    {
        return $user !== null && ($user->current_tenant_id === null || (string) $user->current_tenant_id === (string) $category->tenant_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, AssetCategory $category): bool
    {
        return $this->view($user, $category);
    }

    public function delete(?User $user, AssetCategory $category): bool
    {
        return $this->view($user, $category);
    }
}
