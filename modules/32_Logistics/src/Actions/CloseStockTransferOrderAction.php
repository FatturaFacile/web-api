<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class CloseStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function execute(string $transferId): StockTransferOrder
    {
        return $this->transfers->close(StockTransferOrder::query()->findOrFail($transferId));
    }
}
