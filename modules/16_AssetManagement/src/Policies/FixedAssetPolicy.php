<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Policies;

use App\Models\User;
use Modules\AssetManagement\Models\FixedAsset;

final class FixedAssetPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, FixedAsset $asset): bool
    {
        return $user !== null && ($user->current_tenant_id === null || (string) $user->current_tenant_id === (string) $asset->tenant_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }

    public function capitalize(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }

    public function depreciate(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }

    public function valuate(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }

    public function transfer(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }

    public function dispose(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }

    public function delete(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }
}
