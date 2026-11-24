<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;

final class DispatchGoodsAction
{
    public function __construct(
        private readonly DeliveryFulfillmentServiceInterface $fulfillmentService
    ) {}

    public function execute(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote
    {
        return $this->fulfillmentService->dispatchDelivery($deliveryNote, $userId);
    }
}
