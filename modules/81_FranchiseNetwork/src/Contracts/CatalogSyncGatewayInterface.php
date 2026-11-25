<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Contracts;

use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\CatalogPush;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * Delivers a central push (catalogue, price list, promotion, loyalty campaign) to one store. The payload is already adjusted to the
 * store jurisdiction (Swiss prices are recommendations only). A failure is reported with a FranchiseNetworkException whose code is
 * stored on the delivery and shown to the franchisor; the delivery is retried later.
 */
interface CatalogSyncGatewayInterface
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws FranchiseNetworkException
     */
    public function deliver(CatalogPush $push, FranchiseStore $store, array $payload): void;
}
