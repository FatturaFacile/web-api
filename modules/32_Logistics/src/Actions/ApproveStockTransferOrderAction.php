<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Inventory\Models\TransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class ApproveStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function execute(string $transferId, ?string $userId): TransferOrder
    {
        return $this->transfers->approve(TransferOrder::query()->findOrFail($transferId), $userId);
    }
}
