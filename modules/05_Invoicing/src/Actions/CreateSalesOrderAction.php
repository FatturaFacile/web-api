<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesOrder;

final class CreateSalesOrderAction
{
    public function __construct(
        private readonly SalesOrderServiceInterface $orderService
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $items
     */
    public function execute(array $data, array $items, bool $autoSubmit = true, ?string $userId = null): SalesOrder
    {
        $order = $this->orderService->createOrder($data, $items);

        if ($autoSubmit) {
            $order = $this->orderService->submitOrder($order, $userId ?? (string) ($data['created_by'] ?? 'system'));
        }

        return $order;
    }
}
