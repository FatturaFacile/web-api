<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

/**
 * A transfer order left its source warehouse (dispatched inside the shipping transaction): listeners add the transport
 * document and the invoicing of the transfer. `$deliveryData` is the carrier and package data given at dispatch.
 */
final readonly class TransferOrderShipped
{
    /**
     * @param  array<string, mixed>  $deliveryData
     */
    public function __construct(
        public string $transferOrderId,
        public string $tenantId,
        public array $deliveryData = [],
    ) {}
}
