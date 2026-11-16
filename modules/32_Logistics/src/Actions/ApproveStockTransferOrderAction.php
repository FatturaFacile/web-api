<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class ApproveStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function execute(string $transferId, ?string $userId): StockTransferOrder
    {
        return $this->transfers->approve(StockTransferOrder::query()->findOrFail($transferId), $userId);
    }
}
