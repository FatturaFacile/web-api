<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Services\DeliveryPointService;

final class AssignInvoiceDeliveryPointsHandler
{
    public function __construct(private readonly DeliveryPointService $points) {}

    public function handle(AssignInvoiceDeliveryPointsCommand $command): int
    {
        return $this->points->assignInvoices($command->assignments, $command->tenantId, $command->userId);
    }
}
