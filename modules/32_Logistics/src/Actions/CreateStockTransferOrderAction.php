<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Inventory\Models\TransferOrder;
use Modules\Logistics\Services\StockTransferService;

final class CreateStockTransferOrderAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * @param  array<string, mixed>  $data  validated payload including `lines`
     */
    public function execute(array $data): TransferOrder
    {
        /** @var list<array{product_id: string, quantity: float|int|string, description?: string}> $lines */
        $lines = $data['lines'];

        return $this->transfers->create($data, $lines);
    }
}
