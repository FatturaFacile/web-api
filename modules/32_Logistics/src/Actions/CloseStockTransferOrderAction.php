<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Inventory\Models\TransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class CloseStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function execute(string $transferId): TransferOrder
    {
        return $this->transfers->close(TransferOrder::query()->findOrFail($transferId));
    }
}
