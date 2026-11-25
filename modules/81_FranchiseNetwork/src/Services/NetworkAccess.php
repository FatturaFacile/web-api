<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * Which side of the network the active company is on. The franchisor owns every governance row (its tenant id is on them) and sees
 * them through the normal tenant scope. A franchisee company only ever reaches the rows of its own store(s), through `portal()`:
 * the rows stay franchisor-owned, the scope is lifted for the store the franchisee is linked to and nothing else, so neither the
 * books of another franchisee nor those of the franchisee itself are ever read.
 */
final class NetworkAccess
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($tenantId === null) {
            throw new FranchiseNetworkException('tenant_missing', 403);
        }

        return $tenantId;
    }

    public function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantId());
    }

    public function isFranchisor(): bool
    {
        return FranchiseStore::query()->exists();
    }

    /**
     * Stores linked to the active company as a franchisee.
     *
     * @return Collection<int, FranchiseStore>
     */
    public function portalStores(): Collection
    {
        return $this->portal(FranchiseStore::query())->orderBy('code')->get();
    }

    /**
     * The store of the franchisee portal: the requested one, or the only one linked to the company.
     */
    public function portalStore(?string $storeId = null): FranchiseStore
    {
        $stores = $this->portalStores();
        $store = $storeId === null ? ($stores->count() === 1 ? $stores->first() : null) : $stores->firstWhere('id', $storeId);

        if ($store === null) {
            throw new FranchiseNetworkException($stores->isEmpty() ? 'portal_not_linked' : 'portal_store_required', $stores->isEmpty() ? 403 : 422);
        }

        return $store;
    }

    /**
     * Lifts the tenant scope of a franchisor-owned model query and keeps only the rows of the stores linked to the active company.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function portal(Builder $query): Builder
    {
        $linked = FranchiseStore::query()->withoutGlobalScope(TenantScope::class)->where('franchisee_tenant_id', $this->tenantId())->select('id');
        $model = $query->getModel();

        $query->withoutGlobalScope(TenantScope::class);

        return $model instanceof FranchiseStore ? $query->where('franchisee_tenant_id', $this->tenantId()) : $query->whereIn($model->qualifyColumn('store_id'), $linked);
    }
}
