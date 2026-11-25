<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class LeadCapturedAndScoredEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $leadId,
        public int $totalScore,
        public bool $isDuplicate,
        public ?string $assignedTerritory,
    ) {}
}
