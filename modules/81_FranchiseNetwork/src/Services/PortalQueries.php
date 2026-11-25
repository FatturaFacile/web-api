<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Modules\FranchiseNetwork\Domain\Enums\AgreementStatus;
use Modules\FranchiseNetwork\Domain\Enums\StatementStatus;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\CatalogPush;
use Modules\FranchiseNetwork\Models\CatalogPushTarget;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;
use Modules\FranchiseNetwork\Models\FranchiseFormula;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\PurchaseRecord;
use Modules\FranchiseNetwork\Models\RoyaltyStatement;
use Modules\FranchiseNetwork\Models\SellOutReport;

/**
 * Read model of the franchisee portal. Every query is restricted to the store the franchisee company is linked to: the franchisor
 * rows are reached with the tenant scope lifted for that store only, and nothing of the franchisee books is ever touched.
 */
final class PortalQueries
{
    public function __construct(private readonly NetworkAccess $access) {}

    /**
     * The agreement in force, or the latest one.
     */
    public function agreement(FranchiseStore $store): ?FranchiseAgreement
    {
        $agreements = $this->access->portal(FranchiseAgreement::query())->with('deferrals')->where('store_id', $store->id)->orderByDesc('start_date')->get();

        return $agreements->firstWhere('status', AgreementStatus::Active) ?? $agreements->first();
    }

    public function formula(FranchiseAgreement $agreement): ?FranchiseFormula
    {
        return $agreement->formula_id === null ? null : FranchiseFormula::query()->withoutGlobalScope(TenantScope::class)->find($agreement->formula_id);
    }

    /**
     * @return Collection<int, PurchaseRecord>
     */
    public function purchases(FranchiseStore $store, int $months = 6): Collection
    {
        return $this->access->portal(PurchaseRecord::query())->where('store_id', $store->id)->orderByDesc('period')->limit($months)->get();
    }

    /**
     * @return Collection<int, CatalogPushTarget>
     */
    public function syncs(FranchiseStore $store): Collection
    {
        $targets = $this->access->portal(CatalogPushTarget::query())->where('store_id', $store->id)->orderByDesc('created_at')->limit(100)->get();
        $pushes = CatalogPush::query()->withoutGlobalScope(TenantScope::class)->whereIn('id', $targets->pluck('push_id'))->get()->keyBy('id');

        return $targets->each(static fn (CatalogPushTarget $target) => $target->setRelation('catalogPush', $pushes->get($target->push_id)));
    }

    public function target(FranchiseStore $store, string $targetId): CatalogPushTarget
    {
        $target = $this->access->portal(CatalogPushTarget::query())->where('store_id', $store->id)->whereKey($targetId)->first();

        if ($target === null) {
            throw new FranchiseNetworkException('push_not_found', 404);
        }

        return $target;
    }

    /**
     * Invoiced statements only: the figures of a run still open can change.
     *
     * @return Collection<int, RoyaltyStatement>
     */
    public function statements(FranchiseStore $store): Collection
    {
        return $this->access->portal(RoyaltyStatement::query())->where('store_id', $store->id)->where('status', StatementStatus::Invoiced->value)->orderByDesc('period')->limit(24)->get();
    }

    /**
     * @return Collection<int, SellOutReport>
     */
    public function sellOut(FranchiseStore $store): Collection
    {
        return $this->access->portal(SellOutReport::query())->where('store_id', $store->id)->orderByDesc('period')->limit(24)->get();
    }
}
