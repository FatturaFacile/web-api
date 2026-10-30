<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;

interface DeliveryFulfillmentServiceInterface
{
    /**
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{sales_order_item_id: string, quantity: float, batch_number?: ?string, serial_number?: ?string}>  $items
     */
    public function createDeliveryNote(SalesOrder $order, array $deliveryData, array $items): SalesDeliveryNote;

    public function dispatchDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote;

    public function confirmDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote;
}
