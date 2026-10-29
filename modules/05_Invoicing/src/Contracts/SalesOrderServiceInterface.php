<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\SalesOrder;

interface SalesOrderServiceInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $items
     */
    public function createOrder(array $data, array $items): SalesOrder;

    public function submitOrder(SalesOrder $order, string $userId): SalesOrder;

    public function approveOrder(SalesOrder $order, string $userId): SalesOrder;

    public function reserveInventory(SalesOrder $order): SalesOrder;

    public function cancelOrder(SalesOrder $order, string $reason, string $userId): SalesOrder;
}
