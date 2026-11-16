<?php

declare(strict_types=1);

namespace Modules\Logistics\Events;

final readonly class ConsignmentConsumptionPosted
{
    public function __construct(
        public string $reportId,
        public string $direction,
        public ?string $generatedDocumentId,
        public string $tenantId,
    ) {}
}
