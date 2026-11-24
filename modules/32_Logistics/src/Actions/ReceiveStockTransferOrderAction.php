<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Inventory\Models\TransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class ReceiveStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * Step 2: in-transit -> destination with variance detection.
     *
     * @param  list<array{line_id: string, received_qty?: float|int|string, damaged_qty?: float|int|string, discrepancy_reason?: ?string}>  $lines
     */
    public function execute(string $transferId, array $lines = [], bool $final = true): TransferOrder
    {
        return $this->transfers->receive(TransferOrder::query()->findOrFail($transferId), $lines, $final);
    }
}
