<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;

final class CreateDeliveryNoteAction
{
    public function __construct(
        private readonly DeliveryFulfillmentServiceInterface $fulfillmentService
    ) {}

    /**
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{sales_order_item_id: string, quantity: float, batch_number?: ?string, serial_number?: ?string}>  $items
     */
    public function execute(SalesOrder $order, array $deliveryData, array $items): SalesDeliveryNote
    {
        return $this->fulfillmentService->createDeliveryNote($order, $deliveryData, $items);
    }
}
