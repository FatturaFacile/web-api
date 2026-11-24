<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;

interface DeliveryFulfillmentServiceInterface
{
    /**
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{sales_order_item_id: string, quantity: float|int|string, batch_number?: ?string, serial_number?: ?string}>  $items
     */
    public function createDeliveryNote(SalesOrder $order, array $deliveryData, array $items): SalesDeliveryNote;

    /**
     * A draft note without an order (goods shipped straight from an accepted quote).
     *
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{product_id?: ?string, description: string, quantity: float|int|string, unit_price?: float|int|string|null, tax_rate?: float|int|string|null}>  $lines
     */
    public function createStandaloneDeliveryNote(string $tenantId, string $contactId, array $deliveryData, array $lines): SalesDeliveryNote;

    /**
     * Deletes a draft note and gives its quantities back to the order; an issued note cannot be deleted.
     */
    public function deleteDraft(SalesDeliveryNote $deliveryNote): void;

    public function dispatchDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote;

    public function confirmDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote;
}
