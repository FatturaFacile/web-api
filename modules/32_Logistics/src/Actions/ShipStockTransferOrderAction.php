<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class ShipStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * Step 1: origin -> in-transit, immutable ledger entries, fiscal DDT and (inter-company) invoice draft.
     *
     * @param  array<string, mixed>  $deliveryData
     */
    public function execute(string $transferId, array $deliveryData = []): StockTransferOrder
    {
        return $this->transfers->ship(StockTransferOrder::query()->findOrFail($transferId), $deliveryData);
    }
}
