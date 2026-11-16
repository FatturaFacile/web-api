<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Inventory\Models\TransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class ShipStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * Step 1: origin -> in-transit, immutable ledger entries, fiscal DDT and (inter-company) invoice draft.
     *
     * @param  array<string, mixed>  $deliveryData
     */
    public function execute(string $transferId, array $deliveryData = []): TransferOrder
    {
        return $this->transfers->ship(TransferOrder::query()->findOrFail($transferId), $deliveryData);
    }
}
