<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class SaveDeliveryPointCommand
{
    /**
     * @param  array<int|string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public string $tenantId,
        public ?string $userId,
        public ?string $deliveryPointId = null,
    ) {}
}
