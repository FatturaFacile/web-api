<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Modules\FranchiseNetwork\Contracts\CatalogSyncGatewayInterface;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreType;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\CatalogPush;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * In-platform delivery: the pushed content is kept with the delivery of the store and read by the franchisee portal (the franchisee
 * company then acknowledges it). A store that is not active, or a franchisee store whose company has not linked itself yet,
 * cannot receive: the delivery fails and is retried.
 */
final class LocalCatalogSyncGateway implements CatalogSyncGatewayInterface
{
    public function deliver(CatalogPush $push, FranchiseStore $store, array $payload): void
    {
        if ($store->status !== StoreStatus::Active) {
            throw new FranchiseNetworkException('store_not_active', 409);
        }

        if ($store->store_type === StoreType::Franchisee && $store->franchisee_tenant_id === null) {
            throw new FranchiseNetworkException('store_not_linked', 409);
        }
    }
}
