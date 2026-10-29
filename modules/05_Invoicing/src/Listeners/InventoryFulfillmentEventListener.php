<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Modules\Invoicing\Models\SalesOrder;

final readonly class InventoryFulfillmentEventListener
{
    /**
     * @param object $event
     */
    public function handleInventoryReserved(object $event): void
    {
        if (isset($event->referenceId) && ($event->referenceType ?? null) === 'sales_order') {
            SalesOrder::where('id', $event->referenceId)->update([
                'inventory_status' => 'reserved',
            ]);
        }
    }
}
