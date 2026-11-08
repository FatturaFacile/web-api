<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * Record-level rules of the fixed assets: the asset must belong to the active company and, for changes, must not be
 * locked by an approval workflow. Role-level read/write access is decided by the module.access middleware.
 */
final class FixedAssetPolicy
{
    /**
     * Any authenticated member may list the assets.
     */
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * The asset belongs to the active company.
     */
    public function view(?User $user, FixedAsset $asset): bool
    {
        return $user !== null && (string) app(TenantContext::class)->getTenantId() === (string) $asset->tenant_id;
    }

    /**
     * Any authenticated member with write access may register assets.
     */
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Changes need an asset of the company not locked by a workflow.
     */
    public function update(?User $user, FixedAsset $asset): bool
    {
        return $this->view($user, $asset) && ! $asset->is_locked;
    }

    /**
     * Capitalization needs an unlocked asset of the company.
     */
    public function capitalize(?User $user, FixedAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    /**
     * Depreciation needs an unlocked asset of the company.
     */
    public function depreciate(?User $user, FixedAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    /**
     * Valuations need an unlocked asset of the company.
     */
    public function valuate(?User $user, FixedAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    /**
     * Transfers need an unlocked asset of the company.
     */
    public function transfer(?User $user, FixedAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    /**
     * Disposals need an unlocked asset of the company.
     */
    public function dispose(?User $user, FixedAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    /**
     * Deletion needs an unlocked asset of the company (the service refuses capitalized ones).
     */
    public function delete(?User $user, FixedAsset $asset): bool
    {
        return $this->update($user, $asset);
    }
}
